<?php
/**
 * Stage C of proposals, run by tests/proposal.php on its database: sending
 * through "Отправить клиенту" (a link per sending, the version locked, the
 * status and the feed), honest views (robots, repeats and employees do not
 * count), "Принять" once and only for the newest sent version, "Задать
 * вопрос" as an incoming message of the site with limits.
 *
 * Uses from proposal.php: $db, $template, $service, proposalExpect().
 */

use skeeks\cms\communication\models\CmsCommLink;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendContext;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsUser;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalQuestion;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalView;
use skeeks\cms\proposal\send\ProposalSendable;
use skeeks\cms\proposal\services\ProposalAnswers;
use skeeks\cms\proposal\services\ProposalFeed;
use skeeks\cms\proposal\services\ProposalViews;

// No site rows: links are built from the path only.
$db->createCommand()->createTable('cms_site', ['id' => 'pk', 'name' => 'string', 'active' => "string default 'Y'", 'is_default' => 'integer default 0', 'priority' => 'integer default 100', 'code' => 'string'])->execute();
$db->createCommand()->createTable('cms_proposal_view', ['id' => 'pk', 'created_at' => 'integer not null', 'cms_proposal_send_id' => 'integer not null', 'user_agent' => 'string', 'ip' => 'string', 'is_bot' => 'integer not null default 0', 'check_reason' => 'string'])->execute();
$db->createCommand()->createTable('cms_proposal_question', ['id' => 'pk', 'created_at' => 'integer not null', 'cms_proposal_id' => 'integer not null', 'cms_proposal_send_id' => 'integer not null', 'cms_proposal_version_id' => 'integer', 'name' => 'string', 'contact' => 'string', 'text' => 'text not null', 'cms_comm_message_id' => 'integer', 'ip' => 'string', 'user_agent' => 'string'])->execute();
$db->createCommand()->createTable('cms_web_notify', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'created_by' => 'integer', 'updated_by' => 'integer', 'name' => 'string', 'comment' => 'text', 'url' => 'string', 'model_code' => 'string', 'model_id' => 'integer', 'cms_user_id' => 'integer', 'is_read' => 'integer default 0',
    'is_seen' => 'integer default 0', 'kind' => 'string', 'category' => 'string', 'importance' => 'integer default 0', 'group_key' => 'string', 'event_key' => 'string', 'count' => 'integer default 1'])->execute();
$db->createCommand()->createTable('cms_notify_setting', ['id' => 'pk', 'cms_user_id' => 'integer', 'scope' => 'string', 'code' => 'string', 'channel' => 'string', 'mode' => 'string', 'updated_at' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_conversation', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'channel' => 'string', 'thread_key' => 'string', 'subject' => 'string', 'last_message_at' => 'integer', 'assigned_user_id' => 'integer', 'assigned_at' => 'integer', 'assigned_by' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_message', ['id' => 'pk', 'created_at' => 'integer', 'updated_at' => 'integer', 'cms_site_id' => 'integer', 'cms_comm_conversation_id' => 'integer', 'cms_comm_account_id' => 'integer', 'channel' => 'string', 'direction' => 'string', 'status' => 'string', 'external_id' => 'string', 'provider_ref' => 'string', 'in_reply_to' => 'string', 'references_list' => 'text', 'from_address' => 'string', 'from_name' => 'string', 'to_list' => 'text', 'cc_list' => 'text', 'subject' => 'string', 'body_text' => 'text', 'body_html' => 'text', 'attachments' => 'text', 'is_internal' => 'integer default 0', 'is_auto' => 'integer default 0', 'is_history' => 'integer default 0', 'message_at' => 'integer', 'sent_by_user_id' => 'integer', 'error' => 'text', 'cms_comm_campaign_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_link', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_message_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer', 'link_type' => 'string', 'cms_log_id' => 'integer'])->execute();
$db->createCommand()->createTable('cms_comm_link_exclusion', ['id' => 'pk', 'created_at' => 'integer', 'created_by' => 'integer', 'cms_comm_conversation_id' => 'integer', 'model_code' => 'string', 'model_id' => 'integer'])->execute();
// Tasks of the rule "задача при первом открытии" (stage E): core tasks and component settings.
$db->createCommand()->createTable('cms_task', ['id' => 'pk', 'created_by' => 'integer', 'created_at' => 'integer', 'name' => 'string not null', 'description' => 'text', 'executor_id' => 'integer', 'cms_project_id' => 'integer', 'cms_company_id' => 'integer', 'cms_user_id' => 'integer', 'cms_lead_id' => 'integer', 'cms_deal_id' => 'integer', 'deal_resource_type' => 'string', 'deal_resource_id' => 'integer', 'is_internal' => 'integer default 0', 'parent_cms_task_id' => 'integer', 'plan_start_at' => 'integer', 'plan_end_at' => 'integer', 'plan_duration' => 'integer', 'fact_duration' => 'integer', 'status' => "string default 'new'", 'executor_sort' => 'integer', 'executor_end_at' => 'integer'])->execute();
$db->createCommand()->createTable('cms_task_file', ['id' => 'pk', 'created_by' => 'integer', 'created_at' => 'integer', 'updated_by' => 'integer', 'updated_at' => 'integer', 'cms_task_id' => 'integer', 'storage_file_id' => 'integer', 'priority' => 'integer'])->execute();
$db->createCommand()->createTable('cms_storage_file', ['id' => 'pk', 'priority' => 'integer'])->execute();
$db->createCommand()->createTable('cms_task_schedule', ['id' => 'pk', 'created_by' => 'integer', 'created_at' => 'integer', 'cms_task_id' => 'integer', 'cms_user_id' => 'integer', 'start_at' => 'integer', 'end_at' => 'integer'])->execute();
$db->createCommand()->createTable('cms_proposal_task', ['id' => 'pk', 'created_at' => 'integer not null', 'cms_proposal_id' => 'integer not null', 'cms_proposal_send_id' => 'integer', 'cms_task_id' => 'integer not null'])->execute();
$db->createCommand()->createTable('cms_component_settings', ['id' => 'pk', 'created_by' => 'integer', 'updated_by' => 'integer', 'created_at' => 'integer', 'updated_at' => 'integer', 'component' => 'string', 'value' => 'text', 'user_id' => 'integer', 'namespace' => 'string', 'cms_site_id' => 'integer'])->execute();
// Component settings load their defaults relative to @webroot.
Yii::setAlias('@webroot', sys_get_temp_dir());
// The client page: nobody is signed in.
Yii::$app->set('user', ['class' => \yii\web\User::class, 'identityClass' => CmsUser::class, 'enableSession' => false, 'enableAutoLogin' => false]);
Yii::$app->set('urlManager', ['class' => \yii\web\UrlManager::class, 'enablePrettyUrl' => true, 'showScriptName' => false, 'baseUrl' => '', 'scriptUrl' => '/index.php',
    'rules' => ['~kp/<code:[A-Za-z0-9_-]{16,64}>' => 'cmsProposal/proposal/view']]);

// 9a. A client person (not an employee) is the fourth kind of client.
$db->createCommand()->batchInsert('cms_user', ['id', 'username', 'first_name', 'last_name', 'is_worker'], [[2, 'anna', 'Анна', 'Петрова', 0]])->execute();
$person = CmsUser::findOne(2);
proposalExpect(\skeeks\cms\proposal\source\ProposalClientSource::value($person) === 'proposal-client:user.2'
    && \skeeks\cms\proposal\source\ProposalClientSource::value(CmsUser::findOne(1)) === null, 'A client person has a record source, an employee has none.');
$personal = new CmsProposal(['cms_site_id' => 1, 'cms_user_id' => 2, 'responsible_id' => 1]);
proposalExpect($personal->save(), 'A proposal of a client person must save: '.json_encode($personal->errors, JSON_UNESCAPED_UNICODE));
proposalExpect($personal->client instanceof CmsUser && $personal->clientTypeName === 'Клиент' && $personal->name !== '', 'The client person is the client.');
proposalExpect(CmsProposal::find()->forRecord($person)->select('id')->column() == [(int)$personal->id], 'The card of the client person lists its proposals.');
$personLog = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 2, 'model_code' => CmsUser::class])->one();
proposalExpect($personLog && (int)$personLog->cms_user_id === 2, 'The feed entry is in the card of the client person.');
$both = new CmsProposal(['cms_site_id' => 1, 'cms_user_id' => 2, 'cms_lead_id' => 20, 'name' => 'Два клиента']);
proposalExpect(!$both->validate() && $both->hasErrors('cms_lead_id'), 'A client person is still one client of four kinds.');

// 10. Sending: a link per sending, the version locked, the status and the feed.
$client = new CmsProposal(['cms_site_id' => 1, 'cms_lead_id' => 20, 'responsible_id' => 1, 'cms_proposal_template_id' => (int)$template->id, 'name' => 'Сайт пекарни']);
proposalExpect($client->save(), 'A proposal to send must save.');
$client->refresh();
$sendable = new ProposalSendable($client);
proposalExpect(str_contains($sendable->getSendTitle(), '«Сайт пекарни»') && str_contains($sendable->getSendText(), '90 000 ₽'), 'The sendable names the proposal and its sum.');
proposalExpect(array_map(static fn($r) => get_class($r).':'.$r->id, $sendable->getSendRecords()) === [CmsLead::class.':20', CmsCompany::class.':10'],
    'The client and its company give the recipients.');

$employee = CmsUser::findOne(1);
$context = new SendContext('proposal', (int)$client->id, 'email', 'client@example.com', $employee);
$context->recipientName = 'Анна';
$url = $sendable->createSendLink($context);
$emailSend = $context->ref;
proposalExpect($emailSend instanceof CmsProposalSend && str_ends_with($url, '/~kp/'.$emailSend->code) && (int)$emailSend->created_by === 1, 'A personal link is made for this sending: '.$url);
proposalExpect(!$client->currentVersion->sent_at, 'The link alone does not lock the version.');
// The PDF of the first sending is already stored: the test has no storage.
$client->currentVersion->updateAttributes(['pdf_storage_file_id' => 501]);
$sendable->afterSend($context, new CmsCommMessage(['id' => 9001]));
$client->refresh();
$emailSend->refresh();
proposalExpect((int)$emailSend->cms_comm_message_id === 9001 && $emailSend->recipient_name === 'Анна', 'The sending keeps its message and recipient.');
proposalExpect($client->currentVersion->sent_at && $client->status === CmsProposal::STATUS_SENT, 'The first sending locks the version and the draft becomes "Отправлено".');
$sentLog = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 20])->orderBy(['id' => SORT_DESC])->one();
proposalExpect(($sentLog->data['event'] ?? null) === ProposalFeed::EVENT_SENT && ($sentLog->data['channel'] ?? null) === 'email', 'The feed says "КП отправлено (письмо)".');

$smsContext = new SendContext('proposal', (int)$client->id, 'sms', '79991234567', $employee);
$sendable->createSendLink($smsContext);
$sendable->afterSend($smsContext, new CmsCommMessage(['id' => 9002]));
$smsSend = $smsContext->ref;
proposalExpect($smsSend->code !== $emailSend->code && $smsSend->recipientText === '+7 999 123-45-67', 'Every channel has its own link; phones read like phones.');

$expired = new CmsProposal(['cms_site_id' => 1, 'cms_lead_id' => 20, 'cms_proposal_template_id' => (int)$template->id, 'valid_until' => time() - 60]);
proposalExpect($expired->save(), 'An expired proposal must save.');
try {
    (new ProposalSendable($expired))->createSendLink(new SendContext('proposal', (int)$expired->id, 'email', 'a@b.c', $employee));
    proposalExpect(false, 'An expired proposal must not be sent.');
} catch (SendException $e) {
    proposalExpect(isset($e->errors['sendable']) && CmsProposalSend::find()->andWhere(['cms_proposal_id' => $expired->id])->count() == 0, 'The refusal names the reason and leaves no link.');
}

// 11. Honest views: only the signal of the page; robots, repeats and employees do not count.
// Sent ten minutes ago: an opening seconds after sending is a link check (tests/presentation.php).
CmsProposalSend::updateAll(['created_at' => time() - 600], ['id' => [(int)$emailSend->id, (int)$smsSend->id]]);
$emailSend->refresh();
$views = new ProposalViews();
$chrome = 'Mozilla/5.0 (Linux; Android 13; CUBOT X30) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36';
foreach (['TelegramBot (like TwitterBot)', 'curl/8.5.0', '', 'Mozilla/5.0 (compatible; YandexBot/3.0)', 'Mozilla/5.0 HeadlessChrome/120.0', 'Mail.RU_Bot/2.0', 'facebookexternalhit/1.1', 'WhatsApp/2.23', 'python-requests/2.31'] as $robot) {
    proposalExpect(ProposalViews::isBot($robot), 'A preview robot: '.$robot);
}
proposalExpect(!ProposalViews::isBot($chrome)
    && !ProposalViews::isBot('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) YaBrowser/24.7 YaApp_iOS/2407 Mobile Safari/604.1')
    && !ProposalViews::isBot('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 YaBrowser/24.10 Safari/537.36'),
    'People are not robots: a Cubot phone and the Yandex apps neither.');
proposalExpect($views->record($emailSend, 'TelegramBot (like TwitterBot)', '1.1.1.1') === ProposalViews::RESULT_BOT, 'A robot is kept in the history.');
proposalExpect($views->record($emailSend, $chrome, '2.2.2.2', true) === ProposalViews::RESULT_STAFF, 'An employee checking the link is not a view.');
$emailSend->refresh();
proposalExpect((int)$emailSend->views_count === 0 && !$emailSend->first_viewed_at, 'Robots and employees do not count.');
proposalExpect($views->record($emailSend, $chrome, '3.3.3.3') === ProposalViews::RESULT_FIRST, 'The page shown in a browser is the first view.');
proposalExpect($views->record($emailSend, $chrome, '3.3.3.3') === ProposalViews::RESULT_REPEAT, 'A reload of the same browser is not a new view.');
proposalExpect($views->record($emailSend, $chrome, '4.4.4.4') === ProposalViews::RESULT_COUNTED, 'Another browser counts.');
$emailSend->refresh();
$client->refresh();
proposalExpect((int)$emailSend->views_count === 2 && $emailSend->first_viewed_at && $emailSend->last_viewed_at, 'The sending counts first, last and how many.');
proposalExpect(CmsProposalView::find()->andWhere(['cms_proposal_send_id' => $emailSend->id])->count() == 3, 'The history has the robot and the two views.');
proposalExpect($client->status === CmsProposal::STATUS_OPENED, 'The first view makes the proposal "Открыто".');
$openedLogs = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 20])->all();
proposalExpect(count(array_filter($openedLogs, static fn($l) => ($l->data['event'] ?? '') === ProposalFeed::EVENT_OPENED && ($l->data['channel'] ?? '') === 'email')) === 1, 'The feed says "КП открыто (письмо)" once.');
proposalExpect(CmsWebNotify::find()->andWhere(['cms_user_id' => 1, 'model_code' => CmsProposal::class, 'model_id' => $client->id])->count() == 1, 'The responsible employee is notified once.');
$kpTasks = \skeeks\cms\proposal\models\CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$client->id])->with('task')->all();
proposalExpect(count($kpTasks) === 1 && (int)$kpTasks[0]->task->executor_id === 1 && (int)$kpTasks[0]->task->cms_lead_id === 20 && (int)$kpTasks[0]->task->cms_company_id === 10
    && str_starts_with($kpTasks[0]->task->name, 'Позвонить: клиент открыл предложение №'.$client->number), 'The first view of a КП gives the responsible employee one call task of the lead and its company.');

// 12. "Принять": only the newest sent version, once.
$answers = new ProposalAnswers();
$firstVersion = $client->currentVersion;
$newer = $service->newVersion($client);
$newer->markSent();
$client->refresh();
try {
    $answers->accept($emailSend, $firstVersion);
    proposalExpect(false, 'An older version must not be accepted.');
} catch (\DomainException $e) {
    proposalExpect(str_contains($e->getMessage(), 'обновлённое'), 'The client is sent to the newer version.');
}
$emailSend = CmsProposalSend::findOne($emailSend->id);
proposalExpect($answers->accept($emailSend, $newer) === true, 'The newest version is accepted.');
proposalExpect($answers->accept($emailSend, $newer) === false, 'A second press changes nothing.');
$client->refresh();
proposalExpect($client->status === CmsProposal::STATUS_ACCEPTED && (int)$client->accepted_version_id === (int)$newer->id && (int)$client->accepted_send_id === (int)$emailSend->id,
    'The proposal knows what was accepted and by which link.');
$acceptedLogs = array_filter(CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 20])->all(), static fn($l) => ($l->data['event'] ?? '') === ProposalFeed::EVENT_ACCEPTED);
proposalExpect(count($acceptedLogs) === 1, 'The feed says "КП принято" once.');

// 13. "Задать вопрос": an incoming message of the client in the CRM, limited.
try {
    $answers->ask($smsSend, null, ' ? ', '', '', '5.5.5.5', $chrome);
    proposalExpect(false, 'An empty question must be refused.');
} catch (\DomainException $e) {
}
$question = $answers->ask($smsSend, $newer, 'Можно ли разбить оплату на три части?', '+7 999 123-45-67', 'Анна', '5.5.5.5', $chrome);
$siteMessage = CmsCommMessage::findOne((int)$question->cms_comm_message_id);
proposalExpect($siteMessage && $siteMessage->channel === 'site' && $siteMessage->direction === 'in' && str_contains((string)$siteMessage->body_text, 'три части'),
    'The question is an incoming message of the site.');
$linked = CmsCommLink::find()->andWhere(['cms_comm_message_id' => $siteMessage->id])->select('model_code')->column();
proposalExpect(in_array(CmsLead::class, $linked, true) && in_array(CmsCompany::class, $linked, true), 'The message is in the feeds of the lead and its company.');
for ($i = 0; $i < 4; $i++) {
    $answers->ask($smsSend, $newer, 'Вопрос '.$i, '', '', '5.5.5.'.($i + 10), $chrome);
}
try {
    $answers->ask($smsSend, $newer, 'Шестой вопрос', '', '', '6.6.6.6', $chrome);
    proposalExpect(false, 'The sixth question of a link within an hour must be refused.');
} catch (\DomainException $e) {
    proposalExpect(CmsProposalQuestion::find()->count() == 5, 'Refused questions are not stored.');
}

// 14. Mailing campaigns of skeeks/cms-communication: templates and shared presentations, a КП of
// each recipient from the template, what became of the sends of campaign letters, the task setting
// of the campaign.
$source = new \skeeks\cms\proposal\send\ProposalSendableSource();
proposalExpect(isset($source->templates(1)[(int)$template->id]) && !isset($source->templates(2)[(int)$template->id]), 'Campaigns list the templates of the site.');
$own = (int)$source->createFromTemplate((int)$template->id, CmsLead::findOne(20), $employee);
$ownProposal = CmsProposal::findOne($own);
proposalExpect($ownProposal && (int)$ownProposal->cms_lead_id === 20 && (int)$ownProposal->responsible_id === 1 && $ownProposal->name === 'Разработка сайта'
    && $ownProposal->currentVersion && count($ownProposal->currentVersion->items) === 3, 'A КП of the recipient is made from the template with its items.');
$states = $source->states([9001, 777]);
proposalExpect(array_keys($states) === [9001] && (int)$states[9001]['opened_at'] === (int)$emailSend->first_viewed_at
    && (int)$states[9001]['accepted_at'] === (int)$client->accepted_at, 'The report of a campaign reads the view and the acceptance of its letter.');
try {
    $source->createFromTemplate(999, CmsLead::findOne(20), $employee);
    proposalExpect(false, 'An unknown template must be refused.');
} catch (\skeeks\cms\communication\send\SendException $e) {
    proposalExpect(isset($e->errors['sendable']), 'The refusal of a template is the reason of the recipient.');
}
$db->createCommand()->createTable('cms_comm_campaign', ['id' => 'pk', 'task_on_open' => 'integer'])->execute();
$db->createCommand()->insert('cms_comm_campaign', ['id' => 5, 'task_on_open' => 0])->execute();
$campaignMessage = new CmsCommMessage(['cms_site_id' => 1, 'cms_comm_conversation_id' => 1, 'channel' => 'email', 'direction' => 'out', 'status' => 'sent',
    'external_id' => 'campaign@test', 'message_at' => time() - 600, 'cms_comm_campaign_id' => 5]);
$campaignMessage->save(false);
$campaignContext = new SendContext('proposal', $own, 'email', 'client@example.com', $employee);
$ownSendable = new ProposalSendable($ownProposal);
$ownSendable->createSendLink($campaignContext);
$ownProposal->currentVersion->updateAttributes(['pdf_storage_file_id' => 502]);
$ownSendable->afterSend($campaignContext, $campaignMessage);
$campaignSend = CmsProposalSend::findOne($campaignContext->ref->id);
$campaignSend->updateAttributes(['created_at' => time() - 600]);
$tasksBefore = (int)\skeeks\cms\proposal\models\CmsProposalTask::find()->count();
proposalExpect($views->record($campaignSend, $chrome, '7.7.7.7') === ProposalViews::RESULT_FIRST
    && (int)\skeeks\cms\proposal\models\CmsProposalTask::find()->count() === $tasksBefore, 'A campaign without the "opened" task gives no task.');
$ownProposal->target_url = 'https://site.test/page?utm_source=own';
$campaignSend->populateRelation('proposal', $ownProposal);
proposalExpect(\skeeks\cms\proposal\services\PresentationLinks::targetUrl($campaignSend) === 'https://site.test/page?utm_source=own&utm_medium=email&utm_campaign=campaign-5',
    'A campaign letter marks its visits with the campaign; marks set by the employee stay.');
