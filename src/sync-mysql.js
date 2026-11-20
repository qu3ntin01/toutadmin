/**
 * MySQL, synchrone.
 *
 * L'édition Node tient sur better-sqlite3, qui est synchrone : mille cent
 * soixante et un appels du produit écrivent `db.prepare(...).get()` et repartent
 * avec la ligne. Rendre tout cela asynchrone ne serait pas un portage mais une
 * réécriture — et une réécriture de chaque contrôleur, pas seulement de l'accès
 * aux données.
 *
 * D'où ce module. La requête part dans un fil de travail qui, lui, a le droit
 * d'attendre ; le fil principal bloque sur un entier partagé et repart avec la
 * réponse. Le modèle d'exécution ne change pas : l'application bloquait déjà
 * pendant chaque lecture SQLite, elle bloque maintenant pendant chaque aller-
 * retour MySQL. Ce qui change, c'est qu'elle peut le faire vers une autre
 * machine.
 *
 * Le prix est honnête : un aller-retour de fil par requête, négligeable devant
 * la latence réseau de MySQL lui-même, et un fil de plus par processus.
 */
const path = require('path');
const { Worker, MessageChannel, receiveMessageOnPort } = require('worker_threads');

class ErreurMysql extends Error {
  constructor(detail) {
    super(detail.message);
    this.name = 'ErreurMysql';
    this.code = detail.code;
    this.errno = detail.errno;
    this.sqlState = detail.sqlState;
  }
}

class ClientSynchrone {
  constructor(config) {
    this.partage = new SharedArrayBuffer(4);
    this.signal = new Int32Array(this.partage);

    const canal = new MessageChannel();
    this.port = canal.port1;
    this.travailleur = new Worker(path.join(__dirname, 'sync-mysql-worker.js'), {
      workerData: { config, shared: this.partage, port: canal.port2 },
      transferList: [canal.port2],
    });
    // Le fil ne retient pas le processus : pendant une requête, c'est le fil
    // principal qui est bloqué, et il suffit à le tenir en vie.
    this.travailleur.unref();
    this.port.unref();
    this.ferme = false;
  }

  /**
   * Une requête, et la réponse, sans repasser par la boucle d'événements.
   *
   * Atomics.wait rend la main dès que le fil de travail a posé son message et
   * levé le signal ; receiveMessageOnPort le lit sans que la boucle ait à
   * tourner — ce qu'elle ne peut pas faire, puisqu'elle est bloquée.
   */
  interroger(sql, params) {
    if (this.ferme) {
      throw new ErreurMysql({ message: 'La connexion est fermée.', code: 'CLOSED', errno: 0, sqlState: '' });
    }

    Atomics.store(this.signal, 0, 0);
    this.travailleur.postMessage({ sql, params: params || [] });
    Atomics.wait(this.signal, 0, 0);

    const enveloppe = receiveMessageOnPort(this.port);
    if (!enveloppe) {
      throw new ErreurMysql({ message: 'Le fil MySQL n’a rien répondu.', code: 'NO_REPLY', errno: 0, sqlState: '' });
    }

    const reponse = enveloppe.message;
    if (!reponse.ok) {
      throw new ErreurMysql(reponse.erreur);
    }
    return reponse;
  }

  fermer() {
    if (this.ferme) return;
    this.ferme = true;
    try {
      this.travailleur.terminate();
    } catch {
      /* déjà parti */
    }
  }
}

module.exports = { ClientSynchrone, ErreurMysql };
