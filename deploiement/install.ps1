<#
=============================================================================
 Toutadmin — édition Node : installation sur Windows
=============================================================================

    .\install.ps1
    .\install.ps1 -Port 3000 -Dossier C:\Toutadmin -Donnees D:\ToutadminDonnees

 Ce que fait ce script : il pose Node, le code, les dossiers, et une tâche qui
 démarre le service avec la machine. Puis il s'arrête et donne une adresse.

 Il ne pose ni base, ni compte d'administration, ni licence : c'est l'assistant
 qui le fait, dans le navigateur, en huit étapes, et qui se ferme de lui-même
 une fois le compte créé.

 ── Le point dur, et comment il est contourné ───────────────────────────────

 Sur dix-sept dépendances, une seule se compile : better-sqlite3. Sous Linux
 cela ne se voit pas ; sous Windows, si aucun binaire précompilé ne
 correspond, npm réclame Visual Studio Build Tools et Python — et c'est
 exactement le moment où l'on abandonne.

 D'où la version de Node figée plus bas : elle est choisie parce que
 better-sqlite3 publie un binaire pour elle. Le script vérifie ensuite que la
 bibliothèque se charge vraiment, et le dit franchement si ce n'est pas le
 cas, au lieu de laisser découvrir la panne au premier écran.

 ── Un service, ou une tâche ? ──────────────────────────────────────────────

 Windows ne sait pas faire un service d'un programme ordinaire : il lui faut
 un exécutable qui parle son protocole, ce que node.exe ne fait pas. Les
 recettes habituelles passent par un outil tiers — NSSM, WinSW — qu'il
 faudrait télécharger.

 Ce script emploie donc une tâche planifiée au démarrage, qui ne demande rien
 d'autre que Windows. Elle redémarre avec la machine et tourne sous SYSTEM.
 Qui préfère un vrai service le verra dans services.msc en posant NSSM ; la
 fin du script dit comment.
=============================================================================
#>
[CmdletBinding()]
param(
    [int]    $Port     = 3000,
    [string] $Dossier  = 'C:\Toutadmin',
    [string] $Donnees  = 'C:\Toutadmin\donnees',
    [string] $Source   = '',
    [string] $VersionNode = '22.11.0'
)

$ErrorActionPreference = 'Stop'

function Titre($t) { Write-Host ''; Write-Host $t -ForegroundColor White }
function Bien($t)  { Write-Host "OK  $t" -ForegroundColor Green }
function Gris($t)  { Write-Host "    $t" -ForegroundColor DarkGray }
function Mal($t)   { Write-Host "ERREUR  $t" -ForegroundColor Red }

# Poser une tâche au démarrage et écrire dans C:\ demandent l'élévation. Le
# dire tout de suite vaut mieux que d'échouer à la moitié du travail.
$estAdmin = ([Security.Principal.WindowsPrincipal] `
    [Security.Principal.WindowsIdentity]::GetCurrent()
    ).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $estAdmin) {
    Mal "Ce script pose une tâche système : ouvrez PowerShell en tant qu'administrateur."
    exit 1
}

Titre 'Toutadmin — installation'
Gris "code     $Dossier"
Gris "données  $Donnees"
Gris "port     $Port"

# ── Node ────────────────────────────────────────────────────────────────────
#
# L'archive .zip officielle, et non l'installeur .msi : elle se pose dans un
# dossier, ne touche à rien d'autre, et laisse choisir la version — ce qui
# est justement ce dont dépend le binaire précompilé de better-sqlite3.
$nodeDir = Join-Path $Dossier 'node'
$nodeExe = Join-Path $nodeDir 'node.exe'
$npmCmd  = Join-Path $nodeDir 'npm.cmd'

function PoserNode {
    if (Test-Path $nodeExe) {
        $v = (& $nodeExe -v).Trim()
        Bien "Node $v est déjà en place."
        return
    }
    Titre "Pose de Node $VersionNode"
    $arch = if ([Environment]::Is64BitOperatingSystem) { 'x64' } else { 'x86' }
    $url  = "https://nodejs.org/dist/v$VersionNode/node-v$VersionNode-win-$arch.zip"
    $zip  = Join-Path $env:TEMP "node-$VersionNode.zip"

    Gris $url
    # TLS 1.2 explicitement : les PowerShell 5.1 d'origine ne le proposent pas
    # toujours, et nodejs.org ne répond plus en deçà.
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing

    $temp = Join-Path $env:TEMP "node-extrait-$([guid]::NewGuid())"
    Expand-Archive -Path $zip -DestinationPath $temp -Force
    $extrait = Get-ChildItem $temp -Directory | Select-Object -First 1
    New-Item -ItemType Directory -Force -Path $Dossier | Out-Null
    Move-Item -Path $extrait.FullName -Destination $nodeDir -Force
    Remove-Item $zip, $temp -Recurse -Force -ErrorAction SilentlyContinue

    if (-not (Test-Path $nodeExe)) { Mal "Node n'est pas là après extraction."; exit 1 }
    Bien "Node $((& $nodeExe -v).Trim())"
}

# ── Le code ─────────────────────────────────────────────────────────────────
function PoserCode {
    Titre 'Pose du code'
    $depuis = if ($Source) { $Source } else { (Get-Location).Path }
    $cible  = Join-Path $Dossier 'app'

    if (-not (Test-Path (Join-Path $depuis 'package.json'))) {
        Mal "Pas de package.json dans « $depuis »."
        Mal "Donnez -Source, ou lancez le script depuis le dossier du produit."
        exit 1
    }

    New-Item -ItemType Directory -Force -Path $cible, $Donnees | Out-Null
    # Ni node_modules, ni data, ni .env : ils appartiennent à l'instance d'où
    # l'on copie, pas à celle qu'on installe.
    robocopy $depuis $cible /E /NFL /NDL /NJH /NJS /NP `
        /XD node_modules .git data .claude tests `
        /XF .env *.log | Out-Null
    if ($LASTEXITCODE -ge 8) { Mal "La copie du code a échoué (robocopy $LASTEXITCODE)."; exit 1 }

    Titre 'Installation des dépendances'
    Gris 'better-sqlite3 va chercher un binaire précompilé — cela prend une minute.'
    Push-Location $cible
    try {
        & $npmCmd ci --omit=dev --no-audit --no-fund
        if ($LASTEXITCODE -ne 0) { throw "npm ci a rendu $LASTEXITCODE" }
    } catch {
        Mal "Les dépendances ne se sont pas installées : $_"
        Mal "Si l'erreur parle de node-gyp, de Python ou de Visual Studio, c'est"
        Mal "que la version de Node ne correspond à aucun binaire précompilé de"
        Mal "better-sqlite3. Relancez avec une autre : -VersionNode 22.11.0"
        exit 1
    } finally { Pop-Location }

    # La vraie question n'est pas « npm a-t-il fini » mais « la bibliothèque
    # se charge-t-elle ». Un binaire pour la mauvaise version d'ABI s'installe
    # sans bruit et ne se charge qu'au premier écran.
    Push-Location $cible
    & $nodeExe -e "require('better-sqlite3'); console.log('ok')" | Out-Null
    $chargee = ($LASTEXITCODE -eq 0)
    Pop-Location
    if (-not $chargee) {
        Mal "better-sqlite3 s'est installé mais ne se charge pas sur ce Node."
        Mal "Relancez avec une autre version : -VersionNode 22.11.0"
        exit 1
    }
    Bien 'Code et dépendances en place, better-sqlite3 se charge.'
}

# ── La tâche de démarrage ───────────────────────────────────────────────────
function PoserTache {
    Titre 'Pose de la tâche de démarrage'
    $cible = Join-Path $Dossier 'app'
    $nom   = 'Toutadmin'

    # Un fichier de lancement plutôt qu'une ligne de commande interminable :
    # il se lit, il se corrige, et il porte les variables au même endroit.
    $lanceur = Join-Path $Dossier 'demarrer.cmd'
    @"
@echo off
rem Lancé par la tâche planifiée « $nom », et utilisable à la main pour voir
rem ce que le serveur écrit.
set NODE_ENV=production
set PORT=$Port
rem Tout ce que le produit range sur le disque suit ce chemin : la base, la
rem clé de session, les photos de profil, le coffre-fort, les CV, le
rem parapheur, les pièces reçues, les sauvegardes.
set DB_PATH=$Donnees\app.sqlite
rem Le code est posé par le script d'installation et remplacé par lui :
rem l'écran « Mises à jour » signale les versions mais n'écrit rien.
set CODE_IMMUABLE=1
cd /d "$cible"
"$nodeExe" src\server.js
"@ | Set-Content -Path $lanceur -Encoding ASCII

    schtasks /Query /TN $nom *> $null
    if ($LASTEXITCODE -eq 0) {
        Gris 'La tâche existe déjà : elle est remplacée.'
        schtasks /Delete /TN $nom /F *> $null
    }
    schtasks /Create /TN $nom /TR "`"$lanceur`"" /SC ONSTART /RU SYSTEM /RL HIGHEST /F *> $null
    if ($LASTEXITCODE -ne 0) { Mal 'La tâche n''a pas pu être créée.'; exit 1 }
    schtasks /Run /TN $nom *> $null
    Bien 'Tâche posée et démarrée.'
}

PoserNode
PoserCode
PoserTache

Titre 'Installé.'
Write-Host "  Ouvrez http://localhost:$Port — l'assistant d'installation vous attend."
Write-Host @"

  Il se déroule en huit étapes et pose lui-même la base, le premier compte et
  la licence. Il se ferme de lui-même ensuite : il n'y a aucun fichier à
  penser à supprimer.

  Sur une machine accessible depuis le réseau, posez d'abord un jeton pour que
  personne ne prenne l'instance entre maintenant et votre première visite :
  ajoutez « set INSTALL_TOKEN=... » dans $Dossier\demarrer.cmd, puis relancez
  la tâche.

  La tâche       : schtasks /Query /TN Toutadmin
  La relancer    : schtasks /End /TN Toutadmin puis schtasks /Run /TN Toutadmin
  À la main      : $Dossier\demarrer.cmd   (pour voir ce que le serveur écrit)
  Ses données    : $Donnees

  Pour un vrai service visible dans services.msc, posez NSSM puis :
      nssm install Toutadmin "$Dossier\demarrer.cmd"
  La tâche planifiée peut alors être supprimée : schtasks /Delete /TN Toutadmin /F
"@
