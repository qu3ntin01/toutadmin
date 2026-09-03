const express = require('express');

const db = require('../db');
const { requireAuth } = require('../middleware/auth');
const { setFlash } = require('../utils');

const router = express.Router();

router.use(requireAuth);

function listContacts(excludeId) {
  return db
    .prepare("SELECT id, first_name, last_name, email FROM users WHERE active = 1 AND id != ? ORDER BY last_name COLLATE NOCASE")
    .all(excludeId);
}

function unreadCount(userId) {
  return db.prepare('SELECT COUNT(*) AS n FROM messages WHERE recipient_id = ? AND read_at IS NULL').get(userId).n;
}

router.get('/', (req, res) => {
  const userId = req.session.user.id;
  const box = req.query.boite === 'envoyes' ? 'envoyes' : 'reception';

  const inbox = db.prepare(`
    SELECT m.*, u.first_name, u.last_name, u.email, u.avatar_file
    FROM messages m JOIN users u ON u.id = m.sender_id
    WHERE m.recipient_id = ?
    ORDER BY m.created_at DESC LIMIT 100
  `).all(userId);

  const sent = db.prepare(`
    SELECT m.*, u.first_name, u.last_name, u.email, u.avatar_file
    FROM messages m JOIN users u ON u.id = m.recipient_id
    WHERE m.sender_id = ?
    ORDER BY m.created_at DESC LIMIT 100
  `).all(userId);

  let opened = null;
  if (req.query.message) {
    opened = db.prepare(`
      SELECT m.*, s.first_name AS sender_first, s.last_name AS sender_last, s.email AS sender_email,
             r.first_name AS recipient_first, r.last_name AS recipient_last
      FROM messages m
      JOIN users s ON s.id = m.sender_id
      JOIN users r ON r.id = m.recipient_id
      WHERE m.id = ? AND (m.recipient_id = ? OR m.sender_id = ?)
    `).get(Number(req.query.message), userId, userId);

    // Marquer comme lu uniquement quand c'est bien le destinataire qui ouvre.
    if (opened && opened.recipient_id === userId && !opened.read_at) {
      db.prepare('UPDATE messages SET read_at = ? WHERE id = ?').run(new Date().toISOString(), opened.id);
      opened.read_at = new Date().toISOString();
    }
  }

  const account = db.prepare('SELECT mail_address, mail_imap_host, mail_smtp_host FROM users WHERE id = ?').get(userId);

  res.render('messages', {
    box,
    inbox,
    sent,
    opened,
    account,
    contacts: listContacts(userId),
    unread: unreadCount(userId),
  });
});

router.post('/', (req, res) => {
  const senderId = req.session.user.id;
  const recipientId = Number(req.body.recipient_id);
  const subject = (req.body.subject || '').trim().slice(0, 200);
  const body = (req.body.body || '').trim().slice(0, 5000);
  const parentId = req.body.parent_id ? Number(req.body.parent_id) : null;

  const fail = (message) => {
    setFlash(req, 'error', message);
    return res.redirect('/messagerie');
  };

  const recipient = db.prepare('SELECT id FROM users WHERE id = ? AND active = 1').get(recipientId);
  if (!recipient || recipientId === senderId) return fail('Destinataire invalide.');
  if (!subject) return fail("L'objet du message est obligatoire.");
  if (!body) return fail('Le message ne peut pas être vide.');

  db.prepare('INSERT INTO messages (sender_id, recipient_id, subject, body, parent_id) VALUES (?, ?, ?, ?, ?)')
    .run(senderId, recipientId, subject, body, parentId);

  setFlash(req, 'success', 'Message envoyé.');
  res.redirect('/messagerie?boite=envoyes');
});

router.post('/:id/supprimer', (req, res) => {
  const userId = req.session.user.id;
  // Chacun ne peut supprimer que les messages qui le concernent.
  db.prepare('DELETE FROM messages WHERE id = ? AND (recipient_id = ? OR sender_id = ?)')
    .run(Number(req.params.id), userId, userId);

  setFlash(req, 'success', 'Message supprimé.');
  res.redirect('/messagerie');
});

module.exports = router;
module.exports.unreadCount = unreadCount;
