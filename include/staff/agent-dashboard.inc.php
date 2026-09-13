<?php
if (!defined('OSTSCPINC') || !$thisstaff) die('Access Denied');
?>
<style>
#agent-dashboard .dash-tiles { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 20px; padding: 0; list-style: none; }
#agent-dashboard .dash-tile { flex: 1 1 150px; border: 1px solid #ddd; border-radius: 4px; padding: 10px 14px; background: #fff; }
#agent-dashboard .dash-tile .count { display: block; font-size: 26px; font-weight: bold; line-height: 1.2; }
#agent-dashboard .dash-tile .dash-label { color: #666; }
#agent-dashboard .dash-tile.alert .count { color: #c0392b; }
#agent-dashboard .dash-breakdowns { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
#agent-dashboard .dash-breakdowns table { flex: 1 1 300px; }
#agent-dashboard .priority { display: inline-block; padding: 1px 6px; border: 1px solid #ccc; border-radius: 3px; }
#agent-dashboard .flag-overdue { color: #c0392b; font-weight: bold; }
#agent-dashboard td.subject { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#agent-dashboard .empty { padding: 12px; text-align: center; color: #666; }
</style>

<div id="agent-dashboard">
  <div class="pull-left flush-left">
    <h2><?php echo __('Agent Dashboard'); ?></h2>
  </div>
  <div class="pull-right flush-right">
    <a class="action-button" href="tickets.php?queue=6">
      <i class="icon-list-alt"></i> <?php echo __('Assigned to Me'); ?>
    </a>
  </div>
  <div class="clear"></div>
  <p><?php echo sprintf(__('Open tickets assigned to %s.'),
        Format::htmlchars($thisstaff->getName())); ?></p>

  <ul class="dash-tiles">
    <li class="dash-tile">
      <span class="count"><?php echo $stats['open']; ?></span>
      <span class="dash-label"><?php echo __('Open'); ?></span>
    </li>
    <li class="dash-tile <?php echo $stats['overdue'] ? 'alert' : ''; ?>">
      <span class="count"><?php echo $stats['overdue']; ?></span>
      <span class="dash-label"><?php echo __('Overdue'); ?></span>
    </li>
    <li class="dash-tile">
      <span class="count"><?php echo $stats['awaiting']; ?></span>
      <span class="dash-label"><?php echo __('Awaiting My Reply'); ?></span>
    </li>
    <li class="dash-tile">
      <span class="count"><?php echo $stats['answered']; ?></span>
      <span class="dash-label"><?php echo __('Answered'); ?></span>
    </li>
    <li class="dash-tile">
      <span class="count"><?php echo $stats['closed_week']; ?></span>
      <span class="dash-label"><?php echo __('Closed (Last 7 Days)'); ?></span>
    </li>
  </ul>

  <?php if ($tickets) { ?>
  <div class="dash-breakdowns">
    <table class="list" border="0" cellspacing="1" cellpadding="2">
      <thead>
        <tr><th><?php echo __('Help Topic'); ?></th><th width="80"><?php echo __('Tickets'); ?></th></tr>
      </thead>
      <tbody>
        <?php foreach ($byTopic as $topic => $count) { ?>
        <tr><td><?php echo Format::htmlchars($topic); ?></td><td><?php echo $count; ?></td></tr>
        <?php } ?>
      </tbody>
    </table>
    <table class="list" border="0" cellspacing="1" cellpadding="2">
      <thead>
        <tr><th><?php echo __('Priority'); ?></th><th width="80"><?php echo __('Tickets'); ?></th></tr>
      </thead>
      <tbody>
        <?php foreach ($byPriority as $info) { ?>
        <tr>
          <td><span class="priority" style="background-color: <?php echo $info['priority']['color'] ?: 'transparent'; ?>"><?php
            echo Format::htmlchars($info['priority']['desc']); ?></span></td>
          <td><?php echo $info['count']; ?></td>
        </tr>
        <?php } ?>
      </tbody>
    </table>
  </div>
  <?php } ?>

  <table class="list" border="0" cellspacing="1" cellpadding="2" width="100%">
    <thead>
      <tr>
        <th width="80"><?php echo __('Ticket'); ?></th>
        <th><?php echo __('Subject'); ?></th>
        <th><?php echo __('From'); ?></th>
        <th><?php echo __('Help Topic'); ?></th>
        <th><?php echo __('Priority'); ?></th>
        <th><?php echo __('Status'); ?></th>
        <th><?php echo __('Last Updated'); ?></th>
        <th><?php echo __('Due Date'); ?></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$tickets) { ?>
      <tr><td colspan="8" class="empty"><?php echo __('No open tickets are assigned to you.'); ?></td></tr>
    <?php }
    foreach ($tickets as $T) {
        $due = $T['duedate'] ?: $T['est_duedate'];
    ?>
      <tr>
        <td><a href="tickets.php?id=<?php echo (int) $T['ticket_id']; ?>"><?php
            echo Format::htmlchars($T['number']); ?></a></td>
        <td class="subject"><a href="tickets.php?id=<?php echo (int) $T['ticket_id']; ?>"
            title="<?php echo Format::htmlchars($T['cdata__subject']); ?>"><?php
            echo Format::htmlchars($T['cdata__subject']); ?></a></td>
        <td><?php echo Format::htmlchars($T['user__name']); ?></td>
        <td><?php echo Format::htmlchars($T['topic__topic']); ?></td>
        <td><span class="priority" style="background-color: <?php echo $T['priority']['color'] ?: 'transparent'; ?>"><?php
            echo Format::htmlchars($T['priority']['desc']); ?></span></td>
        <td>
          <?php if ($T['isoverdue']) { ?><span class="flag-overdue"><?php echo __('Overdue'); ?></span><br/><?php } ?>
          <?php echo $T['isanswered'] ? __('Answered') : __('Awaiting My Reply'); ?>
        </td>
        <td title="<?php echo Format::htmlchars(Format::datetime($T['lastupdate'])); ?>"><?php
            echo Format::htmlchars(Format::relativeTime(Misc::db2gmtime($T['lastupdate']))); ?></td>
        <td><?php echo $due ? Format::htmlchars(Format::datetime($due)) : '&mdash;'; ?></td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
</div>
