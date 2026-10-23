<?php
/**
 * Read tools of proposals for skeeks/cms-mcp (mcp\*), against a local
 * application with cms-mcp installed, in a read-only transaction:
 * PROPOSAL_MCP_TEST=local php tests/mcp-read.php. Identities: TEST_ADMIN_ID
 * (1), TEST_WORKER_ID (1663).
 */
if (getenv('PROPOSAL_MCP_TEST') !== 'local') {
    throw new RuntimeException('Set PROPOSAL_MCP_TEST=local.');
}
define('ROOT_DIR', getenv('TEST_APP_ROOT') ?: '/app');
define('YII_ENV', 'dev');
define('YII_DEBUG', true);
require ROOT_DIR.'/vendor/skeeks/cms/bootstrap.php';
$config = new Yiisoft\Config\Config(new Yiisoft\Config\ConfigPaths(ROOT_DIR, 'config'), null, [Yiisoft\Config\Modifier\RecursiveMerge::groups('web', 'web-'.ENV, 'params', 'params-web-'.ENV)], 'params-web-'.ENV);
$c = $config->get($config->has('web-'.ENV) ? 'web-'.ENV : 'web');
$c['components']['request']['scriptFile'] = ROOT_DIR.'/frontend/web/index.php';
$c['components']['request']['scriptUrl'] = '/index.php';
$c['components']['request']['hostInfo'] = getenv('TEST_HOST_INFO') ?: 'http://localhost';
$c['components']['user']['enableSession'] = false;
new yii\web\Application($c);
Yii::$app->errorHandler->unregister();

use skeeks\cms\mcp\crm\CrmRecord;
use skeeks\cms\models\CmsUser;
use skeeks\cms\oauth2\models\CmsOauthAccessToken;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;

if (!Yii::$app->has('cmsMcp')) {
    echo "SKIP: skeeks/cms-mcp is not installed\n";
    return;
}
$checks = 0;
function check($ok, $message) { global $checks; if (!$ok) { throw new RuntimeException($message); } ++$checks; }
function rejects(callable $callback, $message) { try { $callback(); } catch (Throwable $e) { ++$GLOBALS['checks']; return; } throw new RuntimeException($message); }
function run(string $tool, array $arguments) {
    static $token;
    $token = $token ?: new CmsOauthAccessToken(['scopes' => json_encode(array_values(Yii::$app->oauth2Server->resources['cms-rest-api']['scopes']))]);
    return Yii::$app->cmsMcp->executeTool($tool, $arguments, $token, ['transport' => 'test']);
}
function listed(array $arguments): array {
    $ids = [];
    for ($offset = 0; ; $offset += 100) {
        $page = run('cms_proposal_list', $arguments + ['limit' => 100, 'offset' => $offset]);
        $ids = array_merge($ids, array_column($page['items'], 'id'));
        if ($offset + 100 >= $page['total']) { sort($ids); return $ids; }
    }
}
function ids($query): array { $ids = array_map('intval', $query->andWhere([CmsProposal::tableName().'.cms_site_id' => (int)Yii::$app->skeeks->site->id])->select(CmsProposal::tableName().'.id')->column()); sort($ids); return $ids; }

$db = Yii::$app->db;
$db->createCommand('SET TRANSACTION READ ONLY')->execute();
$tx = $db->beginTransaction();
try {
    $admin = CmsUser::findOne((int)(getenv('TEST_ADMIN_ID') ?: 1));
    $worker = CmsUser::findOne((int)(getenv('TEST_WORKER_ID') ?: 1663));
    check($admin && $worker, 'Local test identities exist');

    // 1. Visibility equals the "КП" section for a worker and an administrator.
    foreach ([$worker, $admin] as $user) {
        Yii::$app->user->setIdentity($user);
        check(listed([]) === ids(CmsProposal::find()->forManager($user)), 'Proposals of user #'.$user->id.' equal forManager()');
    }
    Yii::$app->user->setIdentity($worker);
    $hidden = CmsProposal::find()->andWhere(['not', ['id' => ids(CmsProposal::find()->forManager($worker)) ?: [0]]])->select('id')->scalar();
    if ($hidden) {
        rejects(function () use ($hidden) { run('cms_proposal_get', ['id' => (int)$hidden]); }, 'A hidden proposal is unavailable');
    }

    // 2. Proposals of a card are forRecord(): the company with the most sendings.
    Yii::$app->user->setIdentity($admin);
    $companyId = (int)CmsProposalSend::find()->select('cms_company_id')->andWhere(['not', ['cms_company_id' => null]])->groupBy('cms_company_id')->orderBy(new yii\db\Expression('COUNT(*) DESC'))->scalar();
    $leadId = (int)CmsProposalSend::find()->select('cms_lead_id')->andWhere(['not', ['cms_lead_id' => null]])->groupBy('cms_lead_id')->orderBy(new yii\db\Expression('COUNT(*) DESC'))->scalar();
    foreach (array_filter(['company' => $companyId, 'lead' => $leadId]) as $type => $id) {
        $record = CrmRecord::resolve(['record_type' => $type, 'record_id' => $id]);
        $expected = ids(CmsProposal::find()->forManager()->forRecord($record->model));
        check(listed(['record_type' => $type, 'record_id' => $id]) === $expected, ucfirst($type).' proposals equal the card block');
        $overview = run('cms_crm_client_overview_get', ['record_type' => $type, 'record_id' => $id]);
        check(isset($overview['proposals']) && $overview['proposals']['count'] === count($expected), 'Overview counts the same proposals');
    }

    // 3. Cards: items and totals of a КП, the page of a presentation, sendings without personal links.
    $proposal = CmsProposal::find()->andWhere(['kind' => CmsProposal::KIND_PROPOSAL])->andWhere(['exists', CmsProposalSend::find()->andWhere(CmsProposalSend::tableName().'.cms_proposal_id = '.CmsProposal::tableName().'.id')])->orderBy(['id' => SORT_DESC])->one();
    $presentation = CmsProposal::find()->andWhere(['kind' => CmsProposal::KIND_PRESENTATION])->orderBy(['id' => SORT_DESC])->one();
    foreach (array_filter([$proposal, $presentation]) as $p) {
        $card = run('cms_proposal_get', ['id' => (int)$p->id]);
        $json = json_encode($card, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        check(strpos($json, '/~kp/') === false && strpos($json, '"code"') === false, 'No personal link or code of a sending');
        foreach (CmsProposalSend::find()->andWhere(['cms_proposal_id' => $p->id])->all() as $send) {
            check(strpos($json, (string)$send->code) === false, 'The code of a sending is not returned');
        }
        check(count($card['sends']) === (int)CmsProposalSend::find()->andWhere(['cms_proposal_id' => $p->id])->count() || count($card['sends']) === 50, 'All sendings');
        if ($p->isPresentation) {
            check($card['target_url'] === (string)$p->target_url && !isset($card['version']), 'A presentation is its page');
        } else {
            check(isset($card['version']['items']) && (float)$card['version']['amount'] === (float)$p->currentVersion->amount, 'КП items and total of the current version');
        }
    }

    // 4. Library: shared presentations only, templates active by default.
    $library = run('cms_proposal_library_get', []);
    foreach ($library['presentations'] as $item) {
        check($item['is_shared'] === true && $item['kind'] === 'presentation', 'Library presentations are shared');
    }
    foreach ($library['templates'] as $item) {
        check($item['is_active'] === true, 'Active templates by default');
    }

    // 5. Proposal events in the history are described with a reference to the card.
    if ($leadId) {
        $history = run('cms_crm_history_get', ['record_type' => 'lead', 'record_id' => $leadId, 'types' => ['proposal'], 'limit' => 50]);
        check($history['total'] > 0, 'The lead has proposal events');
        foreach ($history['items'] as $item) {
            check(isset($item['subtype']) && (isset($item['ref']) || ($item['access'] ?? true) === false), 'Event has its kind and the card');
        }
    }

    echo "OK: {$checks} checks\n";
} finally {
    $tx->rollBack();
}
