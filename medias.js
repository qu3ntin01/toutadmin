/* Les captures, et ce qu'elles montrent. Toutes viennent d'une instance
   réelle peuplée d'un jeu de démonstration : aucune maquette. */
module.exports = {
  // Les écrans qui existent dans les deux thèmes : la page bascule l'image
  // avec elle, sans quoi un site sombre montrerait des captures claires.
  pairs: ['pilotage', 'membre-espace', 'gestion', 'projets', 'juridique', 'securite', 'assistant'],
  tour: ['pilotage', 'membre-espace', 'rh', 'gestion', 'projets', 'juridique'],
  hero: 'membre-espace',
  /*
   * Les écrans que la fenêtre du héros fait défiler d'elle-même.
   *
   * Un héros immobile ne montre qu'un écran sur les cent cinquante. Trois
   * suffisent à dire ce qu'est le produit — ce que voit un salarié, ce que
   * voit une direction, ce que voit une comptabilité — et trois est aussi ce
   * qu'on peut précharger sans peser sur l'arrivée de la page.
   *
   * Tous trois sont dans `pairs` : la fenêtre change d'écran sans jamais
   * montrer une interface claire sur un site sombre.
   */
  heroTour: ['membre-espace', 'pilotage', 'gestion'],
  /*
   * Où se trouve, dans chaque écran, l'entrée de menu qui l'a ouvert.
   *
   * Les trois captures ont le même rail à gauche, et dans chacune
   * l'entrée active est déjà surlignée — « Mon espace », « Pilotage »,
   * « Gestion ». Le curseur de démonstration va s'y poser avant que l'écran
   * ne change : ce qu'on voit alors n'est pas un diaporama, c'est quelqu'un
   * qui se sert du logiciel, et le surlignage qui suit est celui du produit,
   * pas un décor ajouté.
   *
   * En pourcentage de la fenêtre, parce que celle-ci se redimensionne —
   * mesurés sur les captures au cadre fenêtre, palette lavande. En arabe, le
   * rail est à droite : le script retourne l'abscisse.
   */
  heroCible: {
    'membre-espace': [8, 12],
    pilotage: [8, 19],
    gestion: [8, 28],
  },
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
      shots: ['pilotage', 'admin', 'rh', 'parcours', 'gestion', 'direction', 'projets', 'support',
        'support-groupes', 'planning', 'qualite', 'sante-securite', 'informatique', 'developpement',
        'evenements', 'juridique', 'alertes', 'securite', 'rgpd', 'parapheur', 'accueil', 'import',
        'integrations', 'assistance-ia', 'sauvegardes'],
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
