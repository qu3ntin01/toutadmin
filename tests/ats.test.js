const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const path = require('path');
const os = require('os');

const { prepareEnvironment, startServer, Client, ADMIN_EMAIL, ADMIN_PASSWORD } = require('./helpers');

const dir = prepareEnvironment();

const createApp = require('../src/app');
const db = require('../src/db');
const ats = require('../src/ats');
const cv = require('../src/cv');

let server;
let baseUrl;

test.before(async () => {
  server = await startServer(createApp());
  baseUrl = `http://127.0.0.1:${server.address().port}`;
});

test.after(() => {
  server.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

const newClient = () => new Client(baseUrl);

async function loginAsAdmin() {
  const admin = newClient();
  await admin.login(ADMIN_EMAIL, ADMIN_PASSWORD);
  return admin;
}

/** Envoie un fichier en multipart, ce que le helper de formulaire ne sait pas faire. */
async function postMultipart(client, url, { field, fileName, mimetype, content, token }) {
  const boundary = '----test' + Math.random().toString(16).slice(2);
  const parts = [];
  // Un jeton nul permet de vérifier qu'un envoi non signé est bien refusé.
  if (token !== null) {
    parts.push(Buffer.from(
      `--${boundary}\r\nContent-Disposition: form-data; name="_csrf"\r\n\r\n${token || client.csrfToken}\r\n`
    ));
  }
  parts.push(Buffer.from(
    `--${boundary}\r\nContent-Disposition: form-data; name="${field}"; filename="${fileName}"\r\n` +
    `Content-Type: ${mimetype}\r\n\r\n`
  ));
  parts.push(Buffer.from(content), Buffer.from(`\r\n--${boundary}--\r\n`));

  const cookie = [...client.cookies].map(([n, v]) => `${n}=${v}`).join('; ');
  return fetch(`${baseUrl}${url}`, {
    method: 'POST',
    headers: { cookie, 'content-type': `multipart/form-data; boundary=${boundary}` },
    body: Buffer.concat(parts),
    redirect: 'manual',
  });
}

async function uploadCv(client, candidateId, options) {
  await client.refreshToken('/rh');
  return postMultipart(client, `/rh/candidats/${candidateId}/cv`, { field: 'cv', ...options });
}

test('Moteur ATS', async (t) => {
  await t.test('normalise accents, casse et ponctuation', () => {
    assert.equal(ats.normalize('Développeur Back-End / C++'), 'developpeur back end c++');
    assert.equal(ats.normalize('  ÉLECTRICITÉ  '), 'electricite');
  });

  await t.test('respecte les frontières de mots', () => {
    const cvText = ats.normalize('Développeur Javascript, Node et React');
    // « java » ne doit pas être trouvé dans « javascript ».
    assert.equal(ats.contains(cvText, 'java'), false);
    assert.equal(ats.contains(cvText, 'javascript'), true);
    assert.equal(ats.contains(ats.normalize('Java 17 et Spring'), 'java'), true);
  });

  await t.test("détecte les années d'expérience annoncées", () => {
    assert.equal(ats.detectExperience("Technicienne — 7 ans d'expérience"), 7);
    assert.equal(ats.detectExperience('Expérience : 12 ans en industrie'), 12);
    assert.equal(ats.detectExperience('Profil junior sans chiffre'), null);
  });

  await t.test('pondère le score par le poids des critères', () => {
    const opening = { min_experience: 0, ats_threshold: 60 };
    const criteria = [
      { id: 1, label: 'Automatisme', keywords: 'API, automate', kind: 'Souhaité', weight: 3 },
      { id: 2, label: 'Hydraulique', keywords: '', kind: 'Souhaité', weight: 1 },
    ];

    // Seul le critère de poids 3 est présent : 3/4 = 75 %.
    const result = ats.evaluate({ cv_text: 'Maintenance et automate Siemens' }, opening, criteria);
    assert.equal(result.score, 75);
    assert.equal(result.rows.find((r) => r.label === 'Automatisme').matched, true);
    assert.equal(result.rows.find((r) => r.label === 'Hydraulique').matched, false);
    assert.equal(result.shortlisted, true);
  });

  await t.test("un critère requis manquant écarte, quel que soit le score", () => {
    const opening = { min_experience: 0, ats_threshold: 50 };
    const criteria = [
      { id: 1, label: 'Soudure', keywords: '', kind: 'Souhaité', weight: 5 },
      { id: 2, label: 'Permis cariste', keywords: 'CACES', kind: 'Requis', weight: 1 },
    ];

    const result = ats.evaluate({ cv_text: 'Expert en soudure TIG et MIG' }, opening, criteria);
    assert.ok(result.score >= 50, 'le score reste élevé');
    assert.deepEqual(result.missingRequired, ['Permis cariste']);
    assert.equal(result.shortlisted, false, 'un requis manquant écarte la candidature');
  });

  await t.test("une expérience sous le minimum écarte aussi", () => {
    const opening = { min_experience: 5, ats_threshold: 0 };
    const criteria = [{ id: 1, label: 'Maintenance', keywords: '', kind: 'Souhaité', weight: 1 }];

    const short = ats.evaluate({ cv_text: 'Maintenance industrielle', experience_years: 2 }, opening, criteria);
    assert.equal(short.experienceShort, true);
    assert.equal(short.shortlisted, false);

    const enough = ats.evaluate({ cv_text: 'Maintenance industrielle', experience_years: 8 }, opening, criteria);
    assert.equal(enough.experienceShort, false);
    assert.equal(enough.shortlisted, true);
  });

  await t.test("sans CV, rien n'est évalué", () => {
    const opening = { min_experience: 0, ats_threshold: 0 };
    const result = ats.evaluate({ cv_text: '' }, opening, [{ id: 1, label: 'X', keywords: '', kind: 'Souhaité', weight: 1 }]);
    assert.equal(result.hasCv, false);
    assert.equal(result.score, 0);
    assert.equal(result.shortlisted, false, "un dossier sans CV n'est jamais retenu automatiquement");
  });

  await t.test("l'expérience saisie prime sur celle devinée dans le CV", () => {
    const opening = { min_experience: 0, ats_threshold: 0 };
    const criteria = [];
    const guessed = ats.evaluate({ cv_text: "10 ans d'expérience" }, opening, criteria);
    assert.equal(guessed.years, 10);
    assert.equal(guessed.yearsSource, 'détectée dans le CV');

    const declared = ats.evaluate({ cv_text: "10 ans d'expérience", experience_years: 3 }, opening, criteria);
    assert.equal(declared.years, 3);
    assert.equal(declared.yearsSource, 'déclarée');
  });
});

test('Extraction des CV', async (t) => {
  await t.test('lit un fichier texte', async () => {
    const text = await cv.extractText(Buffer.from('Automatisme   et\r\nhydraulique'), 'text/plain');
    assert.equal(text, 'Automatisme et\nhydraulique');
  });

  await t.test('lit le texte d\'un .docx', () => {
    // Un .docx minimal : une archive ZIP contenant word/document.xml non compressé.
    const xml = Buffer.from(
      '<w:document><w:body><w:p><w:r><w:t>Habilitation électrique B1V</w:t></w:r></w:p>' +
      '<w:p><w:r><w:t>Automatisme &amp; API</w:t></w:r></w:p></w:body></w:document>'
    );
    const name = Buffer.from('word/document.xml');
    const header = Buffer.alloc(30);
    header.writeUInt32LE(0x04034b50, 0);
    header.writeUInt16LE(0, 8); // stocké, sans compression
    header.writeUInt32LE(xml.length, 18);
    header.writeUInt16LE(name.length, 26);
    header.writeUInt16LE(0, 28);

    const text = cv.extractDocx(Buffer.concat([header, name, xml]));
    assert.match(text, /Habilitation électrique B1V/);
    // L'entité XML est rendue, et le paragraphe devient un saut de ligne.
    assert.match(text, /Automatisme & API/);
    assert.match(text, /\n/);
  });
});

test('CV et filtrage dans le recrutement', async (t) => {
  const admin = await loginAsAdmin();

  await admin.refreshToken('/rh');
  await admin.post('/rh/postes', { title: 'Technicien de maintenance', contract_type: 'CDI' });
  const opening = db.prepare('SELECT * FROM job_openings').get();

  await t.test('règle le seuil et l\'expérience minimale', async () => {
    await admin.refreshToken('/rh');
    await admin.post(`/rh/postes/${opening.id}/ats`, { ats_threshold: '70', min_experience: '5' });

    const updated = db.prepare('SELECT * FROM job_openings WHERE id = ?').get(opening.id);
    assert.equal(updated.ats_threshold, 70);
    assert.equal(updated.min_experience, 5);
  });

  await t.test('refuse un seuil hors bornes', async () => {
    await admin.refreshToken('/rh');
    await admin.post(`/rh/postes/${opening.id}/ats`, { ats_threshold: '150', min_experience: '5' });
    assert.match((await admin.flash('/rh')).message, /Seuil invalide/);
    assert.equal(db.prepare('SELECT ats_threshold FROM job_openings WHERE id = ?').get(opening.id).ats_threshold, 70);
  });

  await t.test('ajoute des critères pondérés', async () => {
    for (const [label, keywords, kind, weight] of [
      ['Automatisme', 'API, automate, Siemens', 'Souhaité', '3'],
      ['Habilitation électrique', 'B1V, habilitation', 'Requis', '2'],
      ['Soudure', 'TIG, MIG', 'Souhaité', '1'],
    ]) {
      await admin.refreshToken('/rh');
      await admin.post(`/rh/postes/${opening.id}/criteres`, { label, keywords, kind, weight });
    }
    assert.equal(ats.criteriaOf(opening.id).length, 3);
  });

  await t.test('refuse un poids hors bornes', async () => {
    await admin.refreshToken('/rh');
    await admin.post(`/rh/postes/${opening.id}/criteres`, { label: 'Trop lourd', keywords: '', kind: 'Souhaité', weight: '99' });
    assert.match((await admin.flash('/rh')).message, /Poids invalide/);
    assert.equal(ats.criteriaOf(opening.id).length, 3);
  });

  let candidateId;

  await t.test("une candidature sans CV n'est pas évaluée", async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/candidats', {
      opening_id: String(opening.id), first_name: 'Nadia', last_name: 'Berger', email: 'nadia@test.local',
    });
    candidateId = db.prepare('SELECT id FROM candidates').get().id;

    const ranked = ats.rankedCandidates(opening.id);
    assert.equal(ranked[0].ats.hasCv, false);
    assert.equal(ranked[0].ats.shortlisted, false);
  });

  await t.test('un CV déposé est extrait, scoré, et téléchargeable', async () => {
    const res = await uploadCv(admin, candidateId, {
      fileName: 'nadia.txt',
      mimetype: 'text/plain',
      content: "Technicienne de maintenance — 7 ans d'expérience. Automate Siemens, habilitation B1V, hydraulique.",
    });
    assert.equal(res.status, 302);

    const candidate = db.prepare('SELECT * FROM candidates WHERE id = ?').get(candidateId);
    assert.match(candidate.cv_text, /automate Siemens/i);
    assert.equal(candidate.cv_name, 'nadia.txt');
    assert.ok(candidate.cv_file, 'le fichier est conservé sous un nom aléatoire');
    assert.notEqual(candidate.cv_file, 'nadia.txt');

    // Automatisme (3) + habilitation (2) = 5/6, soit 83 %.
    assert.equal(candidate.ats_score, 83);

    const ranked = ats.rankedCandidates(opening.id)[0];
    assert.equal(ranked.ats.shortlisted, true);
    assert.equal(ranked.ats.years, 7);

    const download = await admin.get(`/rh/candidats/${candidateId}/cv`);
    assert.equal(download.status, 200);
    assert.match(await download.text(), /habilitation B1V/);
  });

  await t.test("un critère requis absent écarte malgré un bon score", async () => {
    await admin.refreshToken('/rh');
    await admin.post('/rh/candidats', {
      opening_id: String(opening.id), first_name: 'Théo', last_name: 'Rimbaud',
    });
    const second = db.prepare("SELECT id FROM candidates WHERE last_name = 'Rimbaud'").get().id;

    await uploadCv(admin, second, {
      fileName: 'theo.txt',
      mimetype: 'text/plain',
      content: "Automate Siemens, soudure TIG. 9 ans d'expérience. Aucune certification réglementaire.",
    });

    const ranked = ats.rankedCandidates(opening.id).find((c) => c.id === second);
    // Automatisme (3) + soudure (1) = 4/6, soit 67 %.
    assert.equal(ranked.ats.score, 67);
    assert.deepEqual(ranked.ats.missingRequired, ['Habilitation électrique']);
    assert.equal(ranked.ats.shortlisted, false);
  });

  await t.test('classe les candidatures du meilleur score au moins bon', async () => {
    const ranked = ats.rankedCandidates(opening.id);
    assert.equal(ranked[0].last_name, 'Berger');
    assert.ok(ranked[0].ats.score >= ranked[1].ats.score);
  });

  await t.test("retirer un critère réévalue tout le poste", async () => {
    const criterion = ats.criteriaOf(opening.id).find((c) => c.label === 'Habilitation électrique');
    await admin.refreshToken('/rh');
    await admin.post(`/rh/criteres/${criterion.id}/supprimer`, {});

    const theo = ats.rankedCandidates(opening.id).find((c) => c.last_name === 'Rimbaud');
    // Sans le critère requis : automatisme (3) + soudure (1) = 4/4, soit 100 %.
    assert.equal(theo.ats.score, 100);
    assert.deepEqual(theo.ats.missingRequired, []);
    assert.equal(theo.ats.shortlisted, true);

    // Le score mémorisé en base suit, il ne reste pas périmé.
    assert.equal(db.prepare('SELECT ats_score FROM candidates WHERE id = ?').get(theo.id).ats_score, 100);
  });

  await t.test('refuse un type de fichier non pris en charge', async () => {
    const res = await uploadCv(admin, candidateId, {
      fileName: 'malveillant.exe',
      mimetype: 'application/x-msdownload',
      content: 'MZ binaire',
    });
    assert.equal(res.status, 302);
    assert.match((await admin.flash('/rh')).message, /Format non pris en charge/);
  });

  await t.test('la CVthèque retrouve un profil par mots-clés', async () => {
    const found = ats.searchCvs('hydraulique');
    assert.equal(found.length, 1);
    assert.equal(found[0].last_name, 'Berger');

    // Plusieurs termes : le dossier qui les a tous remonte en tête.
    const both = ats.searchCvs('automate soudure');
    assert.equal(both[0].last_name, 'Rimbaud');
    assert.equal(both[0].matchedAll, true);

    assert.equal(ats.searchCvs('plomberie').length, 0);
  });

  await t.test('supprimer un CV efface le fichier et le texte', async () => {
    const before = db.prepare('SELECT * FROM candidates WHERE id = ?').get(candidateId);
    const filePath = cv.pathOf(before.cv_file);
    assert.ok(fs.existsSync(filePath), 'le fichier existe avant suppression');

    await admin.refreshToken('/rh');
    await admin.post(`/rh/candidats/${candidateId}/cv/supprimer`, {});

    const after = db.prepare('SELECT * FROM candidates WHERE id = ?').get(candidateId);
    assert.equal(after.cv_file, null);
    assert.equal(after.cv_text, '');
    assert.equal(fs.existsSync(filePath), false, 'le fichier est retiré du disque');
    assert.equal(ats.searchCvs('hydraulique').length, 0);
  });

  await t.test("le personnel n'accède ni au CV ni aux critères", async () => {
    await admin.refreshToken('/admin');
    await admin.post('/admin/employes', {
      first_name: 'Curieux', last_name: 'Voisin', grade: 'Employé', contract_type: 'CDI', email: 'curieux.ats@test.local',
    });
    const password = (await admin.flash('/admin')).message.match(/Mot de passe temporaire : ([A-Za-z0-9]+)/)[1];
    const employee = newClient();
    await employee.login('curieux.ats@test.local', password);

    const theo = db.prepare("SELECT id FROM candidates WHERE last_name = 'Rimbaud'").get().id;
    assert.equal((await employee.get(`/rh/candidats/${theo}/cv`)).status, 403);

    const res = await employee.post(`/rh/postes/${opening.id}/criteres`, { label: 'Pirate', kind: 'Requis', weight: '1' });
    assert.equal(res.status, 403);
  });
  await t.test('un envoi multipart sans jeton CSRF est refusé, sans rien écrire', async () => {
    const theo = db.prepare("SELECT * FROM candidates WHERE last_name = 'Rimbaud'").get();
    await admin.refreshToken('/rh');

    const res = await postMultipart(admin, `/rh/candidats/${theo.id}/cv`, {
      field: 'cv', fileName: 'forge.txt', mimetype: 'text/plain', content: 'contenu forge', token: null,
    });
    assert.equal(res.status, 403);

    const after = db.prepare('SELECT * FROM candidates WHERE id = ?').get(theo.id);
    assert.equal(after.cv_file, theo.cv_file, "le CV en place n'a pas été remplacé");
    assert.equal(fs.readdirSync(cv.CV_DIR).length, 1, "aucun fichier orphelin n'a été déposé");
  });

  await t.test("l'envoi d'une photo de profil suit la même règle", async () => {
    // Un PNG minimal valide : l'en-tête suffit, seul le type MIME est filtré.
    const png = Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex');
    await admin.refreshToken('/mon-profil');

    const refused = await postMultipart(admin, '/mon-profil/photo', {
      field: 'avatar', fileName: 'a.png', mimetype: 'image/png', content: png, token: null,
    });
    assert.equal(refused.status, 403);
    assert.equal(db.prepare("SELECT avatar_file FROM users WHERE email = 'admin@test.local'").get().avatar_file, null);

    const accepted = await postMultipart(admin, '/mon-profil/photo', {
      field: 'avatar', fileName: 'a.png', mimetype: 'image/png', content: png,
    });
    assert.equal(accepted.status, 302);
    assert.ok(db.prepare("SELECT avatar_file FROM users WHERE email = 'admin@test.local'").get().avatar_file);
  });
});
