<?php
/*********************************************************************
    agent-dashboard.php

    Landing page for agents: tickets assigned to the logged-in agent.
**********************************************************************/
require('staff.inc.php');

$priorities = array();
foreach (Priority::objects() as $P) {
    $priorities[$P->priority_id] = array(
        'desc' => $P->priority_desc,
        'color' => preg_match('/^#[0-9a-fA-F]{3,6}$/', $P->priority_color)
            ? $P->priority_color : '',
        'urgency' => (int) $P->priority_urgency,
    );
}
$defaultPriorityId = $cfg->getDefaultPriorityId();

$rows = Ticket::objects()
    ->filter(array(
        'staff_id' => $thisstaff->getId(),
        'status__state' => 'open',
    ))
    ->order_by('-isoverdue', 'isanswered', 'lastupdate')
    ->values('ticket_id', 'number', 'lastupdate', 'isoverdue', 'isanswered',
        'est_duedate', 'duedate', 'topic__topic', 'status__name',
        'user__name', 'cdata__subject', 'cdata__priority');

$tickets = array();
$stats = array('open' => 0, 'overdue' => 0, 'awaiting' => 0, 'answered' => 0);
$byTopic = array();
$byPriority = array();

foreach ($rows as $row) {
    $priorityId = $row['cdata__priority'];
    if (!isset($priorities[$priorityId]))
        $priorityId = $defaultPriorityId;
    $row['priority'] = $priorities[$priorityId] ?? array('desc' => '', 'color' => '', 'urgency' => PHP_INT_MAX);
    $tickets[] = $row;

    $stats['open']++;
    if ($row['isoverdue'])
        $stats['overdue']++;
    if ($row['isanswered'])
        $stats['answered']++;
    else
        $stats['awaiting']++;

    $topic = $row['topic__topic'] ?: __('No Help Topic');
    $byTopic[$topic] = ($byTopic[$topic] ?? 0) + 1;

    $pkey = $row['priority']['desc'];
    if (!isset($byPriority[$pkey]))
        $byPriority[$pkey] = array('priority' => $row['priority'], 'count' => 0);
    $byPriority[$pkey]['count']++;
}
arsort($byTopic);
uasort($byPriority, function($a, $b) {
    return $a['priority']['urgency'] <=> $b['priority']['urgency'];
});

$stats['closed_week'] = Ticket::objects()
    ->filter(array(
        'staff_id' => $thisstaff->getId(),
        'status__state' => 'closed',
        'closed__gte' => SqlFunction::NOW()->minus(SqlInterval::DAY(7)),
    ))
    ->count();

$nav->setTabActive('dashboard');
$ost->setPageTitle(__('Agent Dashboard'));

require(STAFFINC_DIR.'header.inc.php');
require(STAFFINC_DIR.'agent-dashboard.inc.php');
include(STAFFINC_DIR.'footer.inc.php');
