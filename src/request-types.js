// Seul le type "Congés payés" décompte le solde de congés à l'approbation.
const REQUEST_TYPES = ['Congés payés', 'RTT', 'Absence maladie', 'Télétravail', 'Autre'];
const BALANCE_IMPACTING_TYPES = ['Congés payés'];

module.exports = REQUEST_TYPES;
module.exports.BALANCE_IMPACTING_TYPES = BALANCE_IMPACTING_TYPES;
module.exports.affectsBalance = (type) => BALANCE_IMPACTING_TYPES.includes(type);
