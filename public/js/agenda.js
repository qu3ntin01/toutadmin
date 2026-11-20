(function () {
  var form = document.querySelector('form[action="/agenda"]');
  if (!form) return;

  var allDay = form.querySelector('input[name="all_day"]');
  var times = form.querySelectorAll('input[type="time"]');
  if (!allDay || times.length === 0) return;

  // Les heures n'ont de sens que sur un événement qui n'occupe pas toute la journée :
  // le serveur les ignore dans ce cas, l'interface le montre plutôt que de le laisser deviner.
  function sync() {
    for (var i = 0; i < times.length; i++) {
      times[i].disabled = allDay.checked;
      if (allDay.checked) times[i].value = '';
    }
  }

  allDay.addEventListener('change', sync);
  sync();

  // La date de fin suit la date de début tant qu'elle ne l'a pas devancée.
  var start = form.querySelector('input[name="start_date"]');
  var end = form.querySelector('input[name="end_date"]');
  if (start && end) {
    start.addEventListener('change', function () {
      if (!end.value || end.value < start.value) end.value = start.value;
    });
  }
})();
