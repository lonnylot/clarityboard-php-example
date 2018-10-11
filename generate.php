<?php

if (!file_exists(__DIR__.'/vendor')) {
  echo "You must run 'composer install' before running this script.\n";
  exit;
}

if (!file_exists(__DIR__.'/.env')) {
  copy(__DIR__.'/.env.example', __DIR__.'/.env');
}

require_once('vendor/autoload.php');

use GuzzleHttp\Promise;
use Carbon\Carbon;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();
try {
  $dotenv->required('API_KEY')->notEmpty();
} catch (Exception $e) {
  echo "You must set API_KEY in '.env' before running this script.\n";
  exit;
}

Clarityboard\Client::setApiKey(getenv('API_KEY'));

// Get our dashboard
echo "Generating 'My Example Dashboard'\n";
$dashboardsResponse = Clarityboard\Dashboard::all();
$dashboards = json_decode($dashboardsResponse->getBody()->getContents());

for($i = 0; $i < count($dashboards); $i++) {
  if ($dashboards[$i]->name == 'My Example Dashboard') {
    $dashboard = $dashboards[$i];
    break;
  }
}

// If we don't have a dashboard then lets create it
if (!isset($dashboard)) {
  $dashboardResponse = Clarityboard\Dashboard::create(['name' => 'My Example Dashboard']);
  $dashboard = json_decode($dashboardResponse->getBody()->getContents());
}

echo "Generating 'Q&A' record group.\n";
$recordGroupResponse = Clarityboard\RecordGroup::update([
  'group' => 'Q&A',
  'data' => [
    'Q/A' => 'Answer',
    'Submitted' => '2018-09-15T15:53:00',
    'Response Time' => '1 Hour'
  ]
]);
$recordGroup = json_decode($recordGroupResponse->getBody()->getContents());

echo "Generating reports for 'My Example Dashboard'.\n";
$promises = [
  Clarityboard\Report::create([
    'dashboardId' => $dashboard->id,
    'name' => 'Total Q&As',
    'chart' => 'timeline',
    'rules' => [
      [
        'type' => 'record-group',
        'value' => $recordGroup->id,
      ],
      [
        'type' => 'field',
        'value' => 'Q/A',
      ],
      [
        'type' => 'date-constraint',
        'value' => 'Submitted'
      ]
    ]
  ]),
  Clarityboard\Report::create([
    'dashboardId' => $dashboard->id,
    'name' => 'Response Time',
    'chart' => 'percentage',
    'rules' => [
      [
        'type' => 'record-group',
        'value' => $recordGroup->id
      ],
      [
        'type' => 'field',
        'value' => 'Response Time'
      ],
      [
        'type' => 'date-constraint',
        'value' => 'Submitted'
      ]
    ]
  ])
];

echo "Creating dummy records...\n";
Promise\settle($promises)->then(function($res) {
  $q_or_a = ['Question', 'Answer'];
  $response_times = ['1 Hour', '2 Hours', '4 Hours'];
  $end_date = Carbon::now();
  $start_date = $end_date->copy()->subWeek();

  $promises = [];

  for(;$start_date->lte($end_date); $start_date->addDay()) {
    $create_records = rand(10, 50);
    for($i = 0; $i < $create_records; $i++) {
      $data = [
        'Q/A' => $q_or_a[array_rand($q_or_a)],
        'Submitted' => $start_date->setHour(rand(0, 23))
      ];

      if ($data['Q/A'] == 'Answer') {
        $data['Response Time'] = $response_times[array_rand($response_times)];
      }

      $promises[] = Clarityboard\Record::create([
        'group' => 'Q&A',
        'data' => $data
      ]);
    }
  }

  Promise\settle($promises)->wait();
})->wait();

echo "Writing example embed...\n";

$template = file_get_contents('index.html.template');
$newContent = str_replace("<!-- Insert Dashboard Embed Code Here -->", $dashboard->embedCode, $template);
file_put_contents('index.html', $newContent);

echo "View your dashboard on https://www.clarityboard.com/dashboards/".$dashboard->id." or open file://".__DIR__."/index.html in your browser.\n";
