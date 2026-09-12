/* Les captures, et ce qu'elles montrent. Toutes viennent d'une instance
   réelle peuplée d'un jeu de démonstration : aucune maquette. */
module.exports = {
  // Les écrans qui existent dans les deux thèmes : la page bascule l'image
  // avec elle, sans quoi un site sombre montrerait des captures claires.
  pairs: ['pilotage', 'membre-espace', 'gestion', 'projets', 'juridique', 'securite'],
  tour: ['pilotage', 'membre-espace', 'rh', 'gestion', 'projets', 'juridique'],
  hero: 'membre-espace',
  groups: [
    {
      key: 'screens.member',
      shots: ['membre-espace', 'membre-equipe', 'membre-demandes', 'membre-agenda', 'membre-salles',
        'membre-messagerie', 'membre-connaissances', 'membre-coffre-fort', 'membre-profil',
        'membre-juridique-membre', 'annuaire', 'organigramme', 'connexion'],
    },
    {
      key: 'screens.admin',
      shots: ['pilotage', 'admin', 'rh', 'gestion', 'direction', 'projets', 'support', 'planning',
        'qualite', 'sante-securite', 'informatique', 'developpement', 'evenements', 'juridique',
        'alertes', 'securite', 'rgpd', 'parapheur', 'sauvegardes'],
    },
    {
      key: 'screens.modules',
      shots: ['comptabilite', 'paie', 'tresorerie', 'immobilisations', 'crm', 'stock'],
    },
    {
      key: 'screens.dark',
      shots: ['pilotage-sombre', 'membre-espace-sombre', 'gestion-sombre', 'projets-sombre',
        'juridique-sombre', 'securite-sombre'],
    },
  ],
  // Une capture par domaine de la page Fonctionnalités.
  domains: {
    socle: 'admin', espace: 'membre-espace', rh: 'rh', talent: 'membre-demandes',
    sst: 'sante-securite', finance: 'gestion', compta: 'comptabilite', achats: 'stock',
    projets: 'projets', support: 'support', ops: 'planning', direction: 'direction',
    it: 'informatique', conformite: 'juridique',
  },
};
