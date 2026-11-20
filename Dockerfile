# =============================================================================
# Toutadmin — édition Node
# =============================================================================
#
# Deux étapes : l'une compile, l'autre exécute. C'est ce qui permet de ne pas
# laisser un compilateur C++ dans l'image finale alors qu'il n'a servi qu'une
# fois, à l'installation de better-sqlite3 — la seule dépendance du produit qui
# se compile, sur dix-sept.
#
#     docker build -t toutadmin:1.0.0 .
#     docker run -p 3000:3000 -v toutadmin-donnees:/donnees toutadmin:1.0.0
#
# Puis ouvrir http://localhost:3000 : l'assistant d'installation s'y déroule en
# huit étapes, comme sur n'importe quelle autre installation. L'image ne pose
# ni base, ni compte, ni licence — c'est l'assistant qui le fait, et il se
# ferme de lui-même ensuite.
# -----------------------------------------------------------------------------

# ── Construction ─────────────────────────────────────────────────────────────
FROM node:22-bookworm-slim AS construction

WORKDIR /app

# better-sqlite3 essaie d'abord un binaire précompilé et ne compile qu'à
# défaut. Les outils sont là pour ce cas-là, et ils restent dans cette
# étape-ci : l'image finale n'en garde rien.
RUN apt-get update \
 && apt-get install -y --no-install-recommends python3 make g++ ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Les deux fichiers de dépendances d'abord, le reste ensuite : tant qu'ils ne
# changent pas, Docker réutilise la couche et l'installation ne se refait pas.
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# ── Exécution ────────────────────────────────────────────────────────────────
FROM node:22-bookworm-slim AS execution

# tini devient le processus numéro un. Sans lui, Node reçoit SIGTERM sans
# gestionnaire par défaut et « docker stop » attend dix secondes avant de tuer
# le conteneur — dix secondes pendant lesquelles une écriture en cours peut
# être interrompue n'importe où.
RUN apt-get update \
 && apt-get install -y --no-install-recommends tini ca-certificates \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=construction /app/node_modules ./node_modules
COPY package.json package-lock.json ./
COPY src ./src
COPY views ./views
COPY public ./public
COPY scripts ./scripts

# Un utilisateur qui n'est pas root. L'image de base en fournit un, « node » ;
# on lui donne le dossier de données, et rien d'autre. Le code lui appartient
# d'ailleurs pas : il n'a aucune raison d'y écrire (voir CODE_IMMUABLE).
RUN mkdir -p /donnees && chown node:node /donnees
VOLUME ["/donnees"]

ENV NODE_ENV=production \
    PORT=3000 \
    DB_PATH=/donnees/app.sqlite \
    # Tout ce que le produit range sur le disque suit le dossier de DB_PATH :
    # la base, instance.json, le secret de session, les photos de profil, le
    # coffre-fort, les CV, le parapheur, les pièces reçues, les sauvegardes.
    # Un seul volume suffit donc — et il suffit aussi pour que l'instance
    # reste la même aux yeux du service de licences après un remplacement.
    #
    # Derrière un reverse-proxy, ce qui est la règle : sans cela, l'adresse IP
    # vue est celle du proxy, et les plafonds par adresse ne protègent plus
    # personne.
    TRUST_PROXY=1 \
    # Le code vit dans l'image et ne s'écrit pas : l'écran « Mises à jour »
    # continue de signaler les versions, mais n'applique plus rien de
    # lui-même. On change de version en changeant de tag.
    CODE_IMMUABLE=1

USER node
EXPOSE 3000

# Le serveur répond sur « / » dans les deux états : redirigé vers l'assistant
# tant qu'il n'est pas installé, vers la connexion ensuite. C'est donc là qu'on
# regarde, et une redirection compte comme une réponse.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD node -e "require('http').get({host:'127.0.0.1',port:process.env.PORT||3000,path:'/'},r=>process.exit(r.statusCode<400?0:1)).on('error',()=>process.exit(1))"

ENTRYPOINT ["/usr/bin/tini", "--"]
CMD ["node", "src/server.js"]
