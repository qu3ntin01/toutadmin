const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');
const { invoicePdf, makePdf, mailWithAttachment } = require('./helpers-pdf');
const { startImapServer } = require('./helpers-imap');

const dir = prepareEnvironment();
process.env.DOCS_DIR = path.join(dir, 'pieces');

const createApp = require('../src/app');
const db = require('../src/db');
const scan = require('../src/invoice-scan');
const intake = require('../src/intake');
const ai = require('../src/ai');
const mailbox = require('../src/mailbox');
const settings = require('../src/settings');
const finance = require('../src/finance');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => server.close());

const newClient = () => new Client(baseUrl);

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

async function makeMember(admin, email, extra = {}) {
  await admin.refreshToken('/admin');
  await admin.post('/admin/employes', {
    first_name: 'Test', last_name: 'Membre', grade: 'Employé', contract_type: 'CDI', email, ...extra,
  });
  const flash = await admin.flash('/admin');
  const password = flash.message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
  const client = newClient();
  await client.firstAccess(email, password);
  return { client, id: db.prepare('SELECT id FROM users WHERE email = ?').get(email).id };
}

test('Lecture d\'une facture par les règles', async (t) => {
  await t.test('les nombres se lisent quelle que soit la convention', () => {
    assert.equal(scan.parseNumber('1 234,56'), 1234.56);
    assert.equal(scan.parseNumber('1.234,56'), 1234.56);
    assert.equal(scan.parseNumber('1,234.56'), 1234.56);
    assert.equal(scan.parseNumber('1 234,56 €'), 1234.56);
    assert.equal(scan.parseNumber('1.234'), 1234, 'trois décimales, c\'est un séparateur de milliers');
    assert.equal(scan.parseNumber('12.34'), 12.34);
    assert.equal(scan.parseNumber('néant'), null);
  });

  await t.test('les dates aussi', () => {
    assert.equal(scan.parseDate('05/01/2026'), '2026-01-05');
    assert.equal(scan.parseDate('5 janvier 2026'), '2026-01-05');
    assert.equal(scan.parseDate('2026-01-05'), '2026-01-05');
    assert.equal(scan.parseDate('32/13/2026'), null);
  });

  await t.test('les identifiants sont vérifiés par leur clé, pas seulement par leur forme', () => {
    assert.equal(scan.luhnValid('73282932000074'), true);
    assert.equal(scan.luhnValid('73282932000075'), false);
    assert.equal(scan.ibanValid('FR7630006000011234567890189'), true);
    assert.equal(scan.ibanValid('FR7630006000011234567890188'), false);

    assert.deepEqual(scan.findSirets('SIRET 732 829 320 00074 sur la facture'), ['73282932000074']);
    assert.deepEqual(scan.findSirets('SIRET 111 111 111 11111'), [], 'un numéro faux n\'est pas un SIRET');
  });

  await t.test('un montant se lit à côté de son étiquette, colonne de blancs comprise', () => {
    const lignes = [
      'Désignation            Qté     PU HT      Total HT',
      'Tôle acier               40     18,50       740,00',
      '',
      'Total HT                                    950,00 €',
      'TVA 20 %                                    190,00 €',
      'Net à payer                               1 140,00 €',
    ].join('\n');

    assert.equal(scan.labelledAmount(lignes, ['total ht']).value, 950, 'le récapitulatif l\'emporte sur l\'en-tête de colonne');
    assert.equal(scan.labelledAmount(lignes, ['tva']).value, 190, 'le pourcentage n\'est pas un montant');
    assert.equal(scan.labelledAmount(lignes, ['net a payer']).value, 1140);
  });

  await t.test('la cohérence HT + TVA = TTC fait la confiance', async () => {
    const texte = await require('../src/cv').extractText(invoicePdf(), 'application/pdf');
    const result = scan.analyse(texte);

    assert.equal(result.coherent, true);
    assert.equal(result.fields.reference, 'F2026-0147');
    assert.deepEqual(
      [result.fields.amountHt, result.fields.amountVat, result.fields.amountTtc],
      [950, 190, 1140],
    );
    assert.equal(result.fields.vatRate, 20);
    assert.equal(result.fields.siret, '73282932000074');
    assert.equal(result.fields.iban, 'FR7630006000011234567890189');
    assert.equal(result.fields.supplierName, 'ACIERS DU NORD SAS');
    assert.ok(result.confidence >= 70);
  });

  await t.test('des montants contradictoires font tomber la confiance et le disent', async () => {
    const texte = await require('../src/cv').extractText(invoicePdf({ ttc: '1 500,00' }), 'application/pdf');
    const result = scan.analyse(texte);

    assert.equal(result.coherent, false);
    assert.ok(result.notes.some((note) => /contredisent/.test(note)));
    assert.ok(result.confidence < 60);
  });

  await t.test('un document sans texte le signale', () => {
    const result = scan.analyse('');
    assert.equal(result.confidence, 0);
    assert.ok(result.notes.some((note) => /scanné en image/.test(note)));
  });

  await t.test('nos propres identifiants ne désignent pas un fournisseur', () => {
    settings.set('company_siren', '552100554');
    const texte = 'Notre société SIREN 552 100 554\nFournisseur SIRET 732 829 320 00074\nTotal TTC 100,00 €';
    const result = scan.analyse(texte);

    assert.equal(result.fields.siret, '73282932000074');
    assert.equal(result.direction, 'Fournisseur');
  });

  await t.test('un tiers connu est reconnu par son identifiant', () => {
    db.prepare("INSERT INTO partners (kind, name, registration) VALUES ('Fournisseur', 'Aciers du Nord', '73282932000074')").run();
    const match = scan.matchPartner('facture de la société', { sirets: ['73282932000074'] });

    assert.equal(match.partner.name, 'Aciers du Nord');
    assert.equal(match.reason, 'identifiant');
  });
});

test('Réception des pièces', async (t) => {
  await t.test('un PDF déposé est lu, scellé et rangé', async () => {
    const outcome = await intake.receive({
      buffer: invoicePdf(),
      originalName: 'facture-aciers.pdf',
      mimeType: 'application/pdf',
      createdBy: 1,
    });

    assert.equal(outcome.ok, true);
    const document = intake.byId(outcome.id);
    assert.equal(document.status, 'À traiter');
    assert.equal(document.source, 'Dépôt');
    assert.equal(document.fields.amountTtc, 1140);
    assert.equal(document.partner_id, db.prepare("SELECT id FROM partners WHERE name = 'Aciers du Nord'").get().id,
      'le tiers connu est rapproché dès la réception');
    assert.equal(intake.verify(document).ok, true);
    assert.equal(fs.statSync(intake.pathOf(document)).mode & 0o777, 0o600);
  });

  await t.test('la même pièce reçue deux fois ne fait qu\'une ligne', async () => {
    const again = await intake.receive({ buffer: invoicePdf(), originalName: 'copie.pdf', mimeType: 'application/pdf' });
    assert.equal(again.ok, false);
    assert.ok(again.duplicate);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM incoming_documents').get().n, 1);
  });

  await t.test('un fichier déguisé est refusé', async () => {
    const outcome = await intake.receive({
      buffer: Buffer.from('MZ exécutable'), originalName: 'facture.pdf', mimeType: 'application/pdf',
    });
    assert.equal(outcome.ok, false);
    assert.match(outcome.message, /type annoncé/);
  });

  await t.test('un PDF sans texte est reçu quand même, avec zéro confiance', async () => {
    const outcome = await intake.receive({
      buffer: makePdf([]), originalName: 'scan.pdf', mimeType: 'application/pdf',
    });
    assert.equal(outcome.ok, true);

    const document = intake.byId(outcome.id);
    assert.equal(document.confidence, 0);
    assert.equal(document.text_length, 0);
    assert.equal(intake.summary().unreadable, 1);
  });

  await t.test('un fichier modifié sur le disque n\'est plus servi', async () => {
    const document = intake.list()[intake.list().length - 1];
    const target = intake.pathOf(document);
    const original = fs.readFileSync(target);

    fs.writeFileSync(target, Buffer.concat([original, Buffer.from('altération')]));
    assert.equal(intake.verify(document).ok, false);

    fs.writeFileSync(target, original);
    assert.equal(intake.verify(document).ok, true);
  });
});

test('Analyse assistée par modèle', async (t) => {
  await t.test('rien n\'est envoyé tant que ce n\'est pas activé', async () => {
    let appelé = false;
    const result = await ai.analyse('Facture', { anthropic: { messages: { parse: async () => { appelé = true; } } } });

    assert.equal(result.ok, false);
    assert.equal(appelé, false, 'le texte ne doit pas sortir sans activation explicite');
  });

  await t.test('activer sans clé est refusé', () => {
    const verdict = ai.setConfig({ provider: 'anthropic', model: 'claude-opus-5', enabled: true });
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /clé/);
    assert.equal(ai.isReady(), false);
  });

  await t.test('la clé est chiffrée en base et jamais réaffichée', () => {
    const verdict = ai.setConfig({ provider: 'anthropic', model: 'claude-opus-5', key: 'sk-ant-secret-essai', enabled: true });
    assert.equal(verdict.ok, true);

    const stored = settings.get(ai.KEYS.key);
    assert.equal(stored.startsWith('enc.v1:'), true);
    assert.equal(stored.includes('sk-ant-secret-essai'), false);
    assert.equal(ai.config().key, 'sk-ant-secret-essai');
    assert.match(ai.displayConfig().key, /défini/);
    assert.equal(ai.displayConfig().key.includes('sk-ant'), false);
  });

  await t.test('une clé laissée vide conserve la précédente', () => {
    ai.setConfig({ provider: 'anthropic', model: 'claude-opus-5', key: '', enabled: true, effort: 'low' });
    assert.equal(ai.config().key, 'sk-ant-secret-essai');
    assert.equal(ai.config().effort, 'low');
  });

  await t.test('l\'appel envoie le texte, un schéma, et rien de plus', async () => {
    let vu = null;
    const stub = {
      messages: {
        parse: async (params) => {
          vu = params;
          return {
            model: 'claude-opus-5',
            stop_reason: 'end_turn',
            parsed_output: {
              fournisseur: 'Papeterie du Centre', reference: 'A-2026-88',
              date_facture: '04/02/2026', montant_ht: 200, montant_tva: 40, montant_ttc: 240,
              taux_tva: 20, devise: 'eur', siret: '732 829 320 00074', iban: null,
            },
          };
        },
      },
    };

    const result = await ai.analyse('Texte de la facture', { anthropic: stub });
    assert.equal(result.ok, true);
    assert.equal(vu.model, 'claude-opus-5');
    assert.equal(vu.output_config.format.type, 'json_schema');
    assert.equal(vu.output_config.effort, 'low');
    assert.equal(vu.messages[0].content, 'Texte de la facture');
    assert.match(vu.system, /facture/);

    assert.equal(result.fields.supplierName, 'Papeterie du Centre');
    assert.equal(result.fields.currency, 'EUR', 'la devise est normalisée');
    assert.equal(result.fields.siret, '73282932000074', 'le SIRET est nettoyé');
    assert.equal(result.fields.issueDate, '2026-02-04', 'la date est ramenée au format ISO');
  });

  await t.test('un refus ou une panne du service ne casse rien', async () => {
    const refus = await ai.analyse('Texte', { anthropic: { messages: { parse: async () => ({ stop_reason: 'refusal' }) } } });
    assert.equal(refus.ok, false);
    assert.match(refus.message, /refusé/);

    const panne = await ai.analyse('Texte', {
      anthropic: { messages: { parse: async () => { throw new Error('réseau coupé'); } } },
    });
    assert.equal(panne.ok, false);
    assert.match(panne.message, /réseau coupé/);
  });

  await t.test('un service compatible OpenAI marche aussi', async () => {
    ai.setConfig({
      provider: 'openai', model: 'mistral-small-latest', baseUrl: 'https://api.exemple.fr/v1',
      key: 'clé-openai', enabled: true,
    });

    let vu = null;
    const fetchImpl = async (url, options) => {
      vu = { url, body: JSON.parse(options.body), headers: options.headers };
      return {
        ok: true,
        status: 200,
        text: async () => JSON.stringify({
          model: 'mistral-small-latest',
          choices: [{ message: { content: JSON.stringify({ fournisseur: 'Mistral', montant_ttc: '1 200,00', devise: 'EUR' }) } }],
        }),
      };
    };

    const result = await ai.analyse('Texte de la facture', { fetchImpl });
    assert.equal(result.ok, true);
    assert.equal(vu.url, 'https://api.exemple.fr/v1/chat/completions');
    assert.equal(vu.headers.authorization, 'Bearer clé-openai');
    assert.equal(vu.body.response_format.type, 'json_object');
    assert.equal(result.fields.amountTtc, 1200);
  });

  await t.test('l\'essai vérifie que le service lit vraiment une facture', async () => {
    const bon = await ai.test({
      fetchImpl: async () => ({
        ok: true, status: 200,
        text: async () => JSON.stringify({
          choices: [{ message: { content: JSON.stringify({ reference: 'A-2026-88', montant_ttc: 240 }) } }],
        }),
      }),
    });
    assert.equal(bon.ok, true);

    const mauvais = await ai.test({
      fetchImpl: async () => ({
        ok: true, status: 200,
        text: async () => JSON.stringify({ choices: [{ message: { content: '{"reference":"autre"}' } }] }),
      }),
    });
    assert.equal(mauvais.ok, false);
    assert.match(ai.status().message, /mal lue/);
  });

  await t.test('les règles gardent la main sur ce qu\'elles vérifient', () => {
    const rules = {
      fields: {
        siret: '73282932000074', iban: 'FR7630006000011234567890189',
        reference: 'F2026-0147', issueDate: '2026-03-12', dueDate: null,
        amountHt: 950, amountVat: 190, amountTtc: 1140, vatRate: 20,
        currency: 'EUR', supplierName: 'ACIERS DU NORD SAS', partnerId: null,
      },
      coherent: true, confidence: 70, notes: [],
    };
    const modèle = {
      siret: '11111111111111', iban: 'FR0000000000000000000000000',
      reference: 'AUTRE', issueDate: '2020-01-01', dueDate: '2026-04-11',
      amountHt: 1, amountVat: 1, amountTtc: 2, vatRate: 5,
      currency: 'USD', supplierName: 'Autre nom', label: 'Tôles et découpe',
    };

    const merged = intake.merge(rules, modèle);
    assert.equal(merged.fields.siret, '73282932000074', 'un SIRET vérifié ne se remplace pas');
    assert.equal(merged.fields.amountTtc, 1140, 'un triplet cohérent ne se remplace pas');
    assert.equal(merged.fields.reference, 'F2026-0147');
    assert.equal(merged.fields.dueDate, '2026-04-11', 'le modèle comble ce qui manquait');
    assert.equal(merged.sources.dueDate, 'ia');
    assert.equal(merged.fields.label, 'Tôles et découpe');
  });

  await t.test('quand les règles se contredisent, un triplet cohérent du modèle l\'emporte', () => {
    const rules = {
      fields: { amountHt: 950, amountVat: 190, amountTtc: 1500, vatRate: null, siret: null, iban: null, partnerId: null },
      coherent: false, confidence: 30, notes: [],
    };
    const merged = intake.merge(rules, { amountHt: 1250, amountVat: 250, amountTtc: 1500, vatRate: 20 });

    assert.equal(merged.fields.amountHt, 1250);
    assert.equal(merged.coherent, true);
    assert.equal(merged.sources.amountHt, 'ia');
    assert.ok(merged.confidence > 30);
  });

  await t.test('une pièce se relit avec le modèle une fois celui-ci activé', async () => {
    ai.setConfig({
      provider: 'openai', model: 'mistral-small-latest', baseUrl: 'https://api.exemple.fr/v1', enabled: true,
    });

    const document = intake.list().find((d) => d.text_length > 0);
    const verdict = await intake.reanalyse(document.id, {
      deps: {
        fetchImpl: async () => ({
          ok: true, status: 200,
          text: async () => JSON.stringify({
            choices: [{ message: { content: JSON.stringify({ objet: 'Tôles acier et découpe laser' }) } }],
          }),
        }),
      },
    });

    assert.equal(verdict.ok, true);
    assert.equal(intake.byId(document.id).fields.label, 'Tôles acier et découpe laser');
  });

  await t.test('le service éteint, la lecture par règles reste entière', async () => {
    ai.setConfig({ provider: 'openai', model: 'mistral-small-latest', baseUrl: 'https://api.exemple.fr/v1', enabled: false });
    assert.equal(ai.isReady(), false);

    const outcome = await intake.receive({
      buffer: invoicePdf({ reference: 'F2026-0200' }), originalName: 'autre.pdf', mimeType: 'application/pdf',
    });
    assert.equal(outcome.ok, true);
    assert.equal(intake.byId(outcome.id).fields.reference, 'F2026-0200');
  });
});

test('Capture de la boîte aux lettres', async (t) => {
  let imap;
  const facture = invoicePdf({ reference: 'F2026-0300' });

  await t.test('la configuration est contrôlée et le mot de passe chiffré', async () => {
    assert.match(mailbox.setConfig({ host: '', user: 'x', action: 'seen' }).message, /hôte/i);
    assert.match(mailbox.setConfig({ host: 'imap.test', user: '', action: 'seen' }).message, /identifiant/i);
    assert.match(mailbox.setConfig({ host: 'imap.test', user: 'x', port: 70000, action: 'seen' }).message, /Port/);
    assert.match(mailbox.setConfig({ host: 'imap.test', user: 'x', action: 'seen', sinceDays: 900 }).message, /fenêtre/);
    assert.match(mailbox.setConfig({ host: 'imap.test', user: 'x', action: 'inventée' }).message, /Action/);

    imap = await startImapServer({
      messages: [
        { uid: 1, source: mailWithAttachment({ attachment: facture }) },
        { uid: 2, source: mailWithAttachment({ subject: 'Simple bonjour' }) },
      ],
    });

    const verdict = mailbox.setConfig({
      host: '127.0.0.1', port: imap.port, secure: false, user: 'factures', password: 'motdepasse',
      folder: 'INBOX', action: 'seen', sinceDays: 30, batch: 10, enabled: true,
    });
    assert.equal(verdict.ok, true);

    const stored = settings.get(mailbox.KEYS.password);
    assert.equal(stored.startsWith('enc.v1:'), true);
    assert.equal(stored.includes('motdepasse'), false);
    assert.match(mailbox.displayConfig().password, /défini/);
  });

  await t.test('le test de connexion ouvre le dossier et compte les non-lus', async () => {
    const verdict = await mailbox.test();
    assert.equal(verdict.ok, true);
    assert.equal(verdict.waiting, 2);
  });

  await t.test('la relève retient les pièces jointes et marque les messages lus', async () => {
    const verdict = await mailbox.fetchOnce();
    assert.equal(verdict.ok, true);
    assert.equal(verdict.scanned, 2);
    assert.equal(verdict.received, 1, 'un courriel sans pièce jointe ne donne pas de pièce');

    const document = intake.list().find((d) => d.source === 'Courriel');
    assert.equal(document.original_name, 'facture.pdf');
    assert.equal(document.mail_subject, 'Facture F2026-0147');
    assert.match(document.mail_from, /compta@aciers\.test/);
    assert.equal(document.fields.reference, 'F2026-0300');

    // Les deux messages sont marqués lus : sans cela, celui sans pièce jointe
    // reviendrait à chaque relève.
    assert.deepEqual(imap.mailbox.map((m) => m.seen), [true, true]);
  });

  await t.test('une seconde relève ne rapporte rien', async () => {
    const avant = db.prepare('SELECT COUNT(*) AS n FROM incoming_documents').get().n;
    const verdict = await mailbox.fetchOnce();

    assert.equal(verdict.ok, true);
    assert.equal(verdict.scanned, 0);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM incoming_documents').get().n, avant);
  });

  await t.test('la même facture reçue deux fois par courriel ne fait qu\'une pièce', async () => {
    imap.mailbox.push({ uid: 3, source: Buffer.from(mailWithAttachment({ attachment: facture }), 'utf8'), seen: false, folder: 'INBOX' });

    const verdict = await mailbox.fetchOnce();
    assert.equal(verdict.scanned, 1);
    assert.equal(verdict.received, 0);
    assert.ok(verdict.skipped.some((s) => /déjà arrivée/.test(s.reason)));
  });

  await t.test('le message peut être rangé dans un dossier plutôt que seulement marqué lu', async () => {
    mailbox.setConfig({
      host: '127.0.0.1', port: imap.port, secure: false, user: 'factures', folder: 'INBOX',
      action: 'move', moveFolder: 'Traitées', sinceDays: 30, batch: 10, enabled: true,
    });

    imap.mailbox.push({
      uid: 4,
      source: Buffer.from(mailWithAttachment({ subject: 'Facture 2', attachment: invoicePdf({ reference: 'F2026-0400' }) }), 'utf8'),
      seen: false,
      folder: 'INBOX',
    });

    const verdict = await mailbox.fetchOnce();
    assert.equal(verdict.received, 1);
    assert.deepEqual(imap.moved, [{ uid: 4, folder: 'Traitées' }]);
    assert.equal(imap.mailbox.find((m) => m.uid === 4).folder, 'Traitées');
  });

  await t.test('aucun message n\'est supprimé : la boîte reste la source', () => {
    assert.equal(imap.mailbox.length, 4);
  });

  await t.test('un mot de passe faux est rendu comme un échec, pas comme une panne', async () => {
    mailbox.setConfig({
      host: '127.0.0.1', port: imap.port, secure: false, user: 'factures', password: 'faux',
      folder: 'INBOX', action: 'seen', sinceDays: 30, batch: 10, enabled: true,
    });

    const verdict = await mailbox.fetchOnce();
    assert.equal(verdict.ok, false);
    assert.match(verdict.message, /Connexion refusée/);
    assert.equal(mailbox.status().ok, false);
  });

  test.after(async () => { if (imap) await imap.close(); });
});

test('Écran du comptable', async (t) => {
  const admin = await loginAsAdmin();

  await t.test('le dépôt passe par l\'écran, avec son jeton', async () => {
    await admin.refreshToken('/pieces');
    const boundary = '----test-piece';
    const pdf = invoicePdf({ reference: 'F2026-0500' });
    const body = Buffer.concat([
      Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n${admin.csrfToken}\r\n`),
      Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="document"; filename="depot.pdf"\r\nContent-Type: application/pdf\r\n\r\n`),
      pdf,
      Buffer.from(`\r\n--${boundary}--\r\n`),
    ]);

    const res = await fetch(`${baseUrl}/pieces/deposer`, {
      method: 'POST',
      headers: {
        cookie: [...admin.cookies].map(([n, v]) => `${n}=${v}`).join('; '),
        'content-type': `multipart/form-data; boundary=${boundary}`,
      },
      body,
      redirect: 'manual',
    });

    assert.equal(res.status, 302);
    const id = Number(res.headers.get('location').split('/').pop());
    assert.equal(intake.byId(id).fields.reference, 'F2026-0500');
  });

  await t.test('la facture créée reprend ce qui est validé à l\'écran, pas ce qui a été lu', async () => {
    const document = intake.list({ status: 'À traiter' }).find((d) => d.fields.reference === 'F2026-0500');
    await admin.refreshToken(`/pieces/${document.id}`);

    // Le comptable corrige le montant avant de valider.
    await admin.post(`/pieces/${document.id}/facturer`, {
      direction: 'Fournisseur',
      partner_id: String(document.partner_id || ''),
      label: 'Tôles acier — mars',
      reference: document.fields.reference,
      issue_date: document.fields.issueDate,
      due_date: document.fields.dueDate,
      amount_ht: '900',
      vat_rate: '20',
      currency: 'EUR',
    });

    const updated = intake.byId(document.id);
    assert.equal(updated.status, 'Facturée');
    assert.ok(updated.invoice_id);

    const invoice = finance.invoiceById(updated.invoice_id);
    assert.equal(invoice.amount_ht, 900, 'la correction du comptable prime sur la lecture');
    assert.equal(invoice.direction, 'Fournisseur');
    assert.match(invoice.notes, /pièce reçue/);
  });

  await t.test('une pièce déjà facturée ne se refacture ni ne se supprime', async () => {
    const document = intake.list({ status: 'Facturée' })[0];
    await admin.refreshToken(`/pieces/${document.id}`);
    await admin.post(`/pieces/${document.id}/facturer`, {
      direction: 'Fournisseur', label: 'Doublon', issue_date: '2026-03-12', amount_ht: '100', vat_rate: '20', currency: 'EUR',
    });

    const message = await admin.flash(`/pieces/${document.id}`);
    assert.match(message.message, /déjà donné lieu/);
    assert.equal(intake.remove(document.id).ok, false);
  });

  await t.test('une pièce écartée se motive, puis se supprime', async () => {
    const outcome = await intake.receive({
      buffer: invoicePdf({ reference: 'F2026-0600' }), originalName: 'relance.pdf', mimeType: 'application/pdf',
    });

    await admin.refreshToken(`/pieces/${outcome.id}`);
    await admin.post(`/pieces/${outcome.id}/ecarter`, { note: 'Relance, pas une facture.' });

    const document = intake.byId(outcome.id);
    assert.equal(document.status, 'Écartée');
    assert.match(document.note, /Relance/);

    const target = intake.pathOf(document);
    await admin.post(`/pieces/${outcome.id}/supprimer`, {});
    assert.equal(intake.byId(outcome.id), null);
    assert.equal(fs.existsSync(target), false);
  });

  await t.test('l\'espace est réservé à la gestion', async () => {
    const salarie = await makeMember(admin, 'paul.comptable@test.local', { first_name: 'Paul', last_name: 'Salarié' });
    assert.equal((await salarie.client.get('/pieces')).status, 403);

    await salarie.client.refreshToken('/mon-espace');
    assert.equal((await salarie.client.post('/pieces/capture/relever', {})).status, 403);
  });

  await t.test('les réglages qui font sortir des données restent à l\'administration', async () => {
    const gestionnaire = await makeMember(admin, 'sonia.gestion2@test.local', { first_name: 'Sonia', last_name: 'Gestion' });
    db.prepare('UPDATE users SET is_finance = 1 WHERE id = ?').run(gestionnaire.id);

    assert.equal((await gestionnaire.client.get('/pieces')).status, 200);

    await gestionnaire.client.refreshToken('/pieces');
    await gestionnaire.client.post('/pieces/analyse/reglages', {
      provider: 'openai', model: 'x', base_url: 'https://ailleurs.test/v1', key: 'clé', enabled: '1',
    });
    let message = await gestionnaire.client.flash('/pieces');
    assert.equal(message.type, 'error');
    assert.match(message.message, /administration/);

    await gestionnaire.client.post('/pieces/capture/reglages', {
      host: 'pirate.test', user: 'x', port: '993', action: 'seen', since_days: '30', batch: '10',
    });
    message = await gestionnaire.client.flash('/pieces');
    assert.equal(message.type, 'error');
    assert.equal(mailbox.config().host, '127.0.0.1', "la configuration n'a pas bougé");
  });
});
