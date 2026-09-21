/* Les captures, et ce qu'elles montrent. Toutes viennent d'une instance
   réelle peuplée d'un jeu de démonstration : aucune maquette. */
module.exports = {
  // Les écrans qui existent dans les deux thèmes : la page bascule l'image
  // avec elle, sans quoi un site sombre montrerait des captures claires.
  pairs: ['pilotage', 'membre-espace', 'gestion', 'projets', 'juridique', 'securite', 'assistant'],
  tour: ['pilotage', 'membre-espace', 'rh', 'gestion', 'projets', 'juridique'],
  hero: 'membre-espace',
  groups: [
    {
      key: 'screens.member',
      shots: ['membre-espace', 'membre-equipe', 'membre-demandes', 'membre-agenda', 'membre-salles',
        'membre-messagerie', 'membre-connaissances', 'membre-coffre-fort', 'membre-profil', 'membre-appareils',
        'membre-juridique-membre', 'membre-cse', 'annuaire', 'organigramme', 'notifications',
        'recherche', 'connexion'],
    },
    {
      key: 'screens.admin',
      shots: ['pilotage', 'admin', 'rh', 'parcours', 'gestion', 'direction', 'projets', 'support', 'planning',
        'qualite', 'sante-securite', 'informatique', 'developpement', 'evenements', 'juridique',
        'alertes', 'securite', 'rgpd', 'parapheur', 'accueil', 'import', 'integrations', 'sauvegardes'],
    },
    {
      /*
       * Ce que le produit sait faire de lui-même. Ces écrans étaient absents
       * du site alors qu'ils répondent aux questions qu'on pose avant
       * d'acheter : ce qui prouve la licence, ce qui le tient à jour, et ce
       * qu'il garde comme trace.
       *
       * « applications » et « sondages » n'y sont pas, et c'est délibéré :
       * sur l'instance de démonstration, la première affiche un bandeau rouge
       * — elle n'est reliée à aucun éditeur, donc sans catalogue à lire — et
       * la seconde est vide, le jeu de démonstration ne posant aucun sondage.
       * Une galerie de vente qui montre un écran d'erreur et un écran vide
       * dessert le produit mieux que ne le servirait leur absence. Le jour où
       * la démonstration portera l'un et l'autre, ils reprendront leur place
       * ici et dans `captures.js`.
       */
      key: 'screens.platform',
      shots: ['licence', 'mises-a-jour', 'journaux'],
    },
    {
      key: 'screens.modules',
      shots: ['comptabilite', 'paie', 'tresorerie', 'immobilisations', 'pieces', 'facturation',
        'crm', 'stock', 'flotte'],
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
