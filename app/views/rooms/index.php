<?php $csrf = \App\Core\Csrf::field(); ?>
<div class="card">
  <div class="org-head">
    <div class="row-actions">
      <a class="btn btn-sm" href="/salles?jour=<?= e($previousDay) ?>">←</a>
      <strong><?= e($date) ?></strong>
      <a class="btn btn-sm" href="/salles?jour=<?= e($nextDay) ?>">→</a>
      <a class="btn btn-sm" href="/salles?jour=<?= e($today) ?>"><?= e(t('agenda.today')) ?></a>
    </div>
  </div>

  <?php if ($rooms === []): ?>
    <div class="empty-state"><?= e(t('erp.noRoom')) ?></div>
  <?php else: ?>
    <div class="grid grid-3">
      <?php foreach ($rooms as $room): ?>
        <div class="sub-card">
          <h3><?= e($room['name']) ?></h3>
          <p class="cell-sub">
            <?= e((string) $room['location']) ?>
            <?php if ((int) $room['capacity'] > 0): ?> · <?= (int) $room['capacity'] ?> <?= e(t('ges.capacity')) ?><?php endif; ?>
          </p>
          <?php $slots = $bookingsByRoom[(int) $room['id']] ?? []; ?>
          <?php if ($slots === []): ?>
            <p class="muted"><?= e(t('rms.freeAllDay')) ?></p>
          <?php else: ?>
            <ul class="people">
              <?php foreach ($slots as $slot): ?>
                <li>
                  <strong><?= e($slot['start_time']) ?> – <?= e($slot['end_time']) ?></strong>
                  <?= e($slot['title']) ?>
                  <span class="cell-sub"> · <?= e(trim($slot['first_name'] . ' ' . $slot['last_name'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card mt-l">
  <h2><?= e(t('erp.book')) ?></h2>
  <form method="POST" action="/salles" class="form-grid">
    <?= $csrf ?>
    <label><span><?= e(t('nav.rooms')) ?></span>
      <select name="room_id" required>
        <option value=""></option>
        <?php foreach ($rooms as $room): ?>
          <option value="<?= (int) $room['id'] ?>"><?= e($room['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label><span><?= e(t('common.title')) ?></span><input type="text" name="title" required maxlength="140" /></label>
    <label><span><?= e(t('common.date')) ?></span><input type="date" name="booking_date" value="<?= e($date) ?>" required /></label>
    <label><span><?= e(t('timer.start_label')) ?></span><input type="time" name="start_time" required /></label>
    <label><span><?= e(t('timer.end_label')) ?></span><input type="time" name="end_time" required /></label>
    <button type="submit" class="btn btn-primary"><?= e(t('erp.book')) ?></button>
  </form>
</div>

<div class="card mt-l">
  <h2><?= e(t('erp.bookings')) ?></h2>
  <?php if ($myBookings === []): ?>
    <div class="empty-state"><?= e(t('rms.noBooking')) ?></div>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('common.date')) ?></th>
          <th><?= e(t('nav.rooms')) ?></th>
          <th><?= e(t('common.title')) ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($myBookings as $booking): ?>
          <tr>
            <td><?= e(\App\Core\Dates::short((string) $booking['booking_date'])) ?><br /><span class="cell-sub"><?= e($booking['start_time']) ?> – <?= e($booking['end_time']) ?></span></td>
            <td><?= e($booking['room_name']) ?></td>
            <td><?= e($booking['title']) ?></td>
            <td>
              <form method="POST" action="/salles/<?= (int) $booking['id'] ?>/annuler">
                <?= $csrf ?>
                <input type="hidden" name="jour" value="<?= e($date) ?>" />
                <button type="submit" class="btn btn-sm"><?= e(t('common.cancel')) ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
