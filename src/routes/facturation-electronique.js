const express = require('express');

const einvoicing = require('../einvoicing');
const facturx = require('../facturx');
const finance = require('../finance');
const modules = require('../modules');
const { requireFinance } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(modules.requireModule('facturation-electronique'), requireFinance);

router.get('/', (req, res) => {
  // Seules les factures clients sont émises : une facture fournisseur est reçue.
  const invoices = finance.invoices({ direction: 'Client' })
    .map((invoice) => ({ ...invoice, conformity: einvoicing.check(invoice) }));

  res.render('einvoicing', {
    issuerFields: einvoicing.ISSUER_FIELDS,
    issuer: einvoicing.issuer(),
    issuerGaps: einvoicing.issuerGaps(),
    invoices,
    readyCount: invoices.filter((i) => i.conformity.ok).length,
  });
});

router.post('/emetteur', (req, res) => {
  const result = einvoicing.saveIssuer(req.body);
  if (!result.ok) {
    setFlash(req, 'error', result.errors.join(' '));
  } else {
    setFlash(req, 'success', "Identité de l'émetteur enregistrée.");
  }
  res.redirect('/facturation-electronique#emetteur');
});

/** Le XML n'est produit que si la facture passe le contrôle : rien d'invalide ne sort. */
router.get('/factures/:id.xml', (req, res) => {
  const invoice = finance.invoiceById(Number(req.params.id));
  if (!invoice || invoice.direction !== 'Client') {
    return res.status(404).render('error', { message: 'Facture client introuvable.' });
  }

  const conformity = einvoicing.check(invoice);
  if (!conformity.ok) {
    setFlash(req, 'error', `Facture non conforme : ${conformity.gaps.join(' · ')}`);
    return res.redirect('/facturation-electronique#factures');
  }

  res.setHeader('Content-Type', 'application/xml; charset=utf-8');
  res.setHeader('Content-Disposition', `attachment; filename="${einvoicing.fileName(invoice)}"`);
  res.send(einvoicing.toXml(invoice));
});

/**
 * Le Factur-X : le PDF lisible et le XML joint, dans le même fichier.
 *
 * Même règle que pour le XML seul — rien ne sort d'une facture qui ne passe
 * pas le contrôle. Un Factur-X non conforme est pire qu'un PDF ordinaire :
 * il promet une conformité qu'il n'a pas.
 */
router.get('/factures/:id.pdf', (req, res) => {
  const invoice = finance.invoiceById(Number(req.params.id));
  if (!invoice || invoice.direction !== 'Client') {
    return res.status(404).render('error', { message: 'Facture client introuvable.' });
  }

  const built = facturx.build(invoice);
  if (!built.ok) {
    setFlash(req, 'error', `Facture non conforme : ${built.gaps.join(' · ')}`);
    return res.redirect('/facturation-electronique#factures');
  }

  res.setHeader('Content-Type', 'application/pdf');
  res.setHeader('Content-Disposition', `attachment; filename="${built.fileName}"`);
  res.send(built.content);
});

module.exports = router;
