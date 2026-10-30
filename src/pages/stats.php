<?php
// Statystyki: SMS dziennie i miesięcznie, doręczenia, najaktywniejsze numery, połączenia, limit miesięczny
$daily = Stats::daily(30);
$monthly = Stats::monthly(12);
render('stats', ['title' => t('nav.stats'), 'nav' => 'stats', 'daily' => $daily, 'monthly' => $monthly, 'delivery' => Stats::delivery(30),
    'top' => Stats::top(5, 30), 'calls' => Stats::calls(30), 'limit' => Stats::limit(),
    'totals' => ['out' => array_sum(array_column($daily, 'out')), 'in' => array_sum(array_column($daily, 'in'))]]);
