<?php
/**
 * Stage E of proposals, run by tests/proposal.php after tests/client.php on
 * its database: the kind "Презентация" (no versions, its own fields, the
 * page checked and kept in the proposal), its sending ("Посмотреть", no
 * files, no version), the address of the transition with marks for
 * Metrika, link checks (seconds after sending, a network of a mail
 * service, an automated browser) that give no status, notification or
 * task, and the rule "задача при первом открытии": one call task of the
 * responsible employee while it is open, the working day, the setting.
 *
 * Uses from proposal.php and client.php: $db, $employee, $chrome, proposalExpect().
 */

use skeeks\cms\base\Component;
use skeeks\cms\communication\models\CmsCommMessage;
use skeeks\cms\communication\send\SendContext;
use skeeks\cms\communication\send\SendException;
use skeeks\cms\models\CmsLog;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsWebNotify;
use skeeks\cms\proposal\components\ProposalSettings;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalTask;
use skeeks\cms\proposal\models\CmsProposalVersion;
use skeeks\cms\proposal\models\CmsProposalView;
use skeeks\cms\proposal\send\ProposalSendable;
use skeeks\cms\proposal\services\PresentationLinks;
use skeeks\cms\proposal\services\ProposalFeed;
use skeeks\cms\proposal\services\ProposalTasks;
use skeeks\cms\proposal\services\ProposalViews;

// 14. A presentation: the page and the text, no versions; the page is checked.
$presentation = new CmsProposal(['cms_site_id' => 1, 'kind' => CmsProposal::KIND_PRESENTATION, 'cms_lead_id' => 21, 'responsible_id' => 1,
    'name' => 'Сайты для магазинов плитки', 'target_url' => ' https://skeeks.com/works/sfery/plitka?utm_source=newsletter#cases ',
    'message' => "Здравствуйте!\nКак договаривались, примеры наших сайтов для магазинов плитки."]);
proposalExpect($presentation->save(), 'A presentation must save: '.json_encode($presentation->errors, JSON_UNESCAPED_UNICODE));
$presentation->refresh();
proposalExpect($presentation->isPresentation && !$presentation->current_version_id
    && CmsProposalVersion::find()->andWhere(['cms_proposal_id' => (int)$presentation->id])->count() == 0, 'A presentation has no versions.');
proposalExpect(!$presentation->valid_until && $presentation->target_url === 'https://skeeks.com/works/sfery/plitka?utm_source=newsletter#cases',
    'A presentation has no validity date by default; the page is trimmed.');
proposalExpect(str_starts_with($presentation->asText, 'Презентация №'.$presentation->number), 'A presentation reads as "Презентация №…": '.$presentation->asText);
proposalExpect(CmsProposal::find()->andWhere(['cms_site_id' => 1])->max('number') == $presentation->number, 'КП and presentations share the numbers of one registry.');
$created = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 21])->orderBy(['id' => SORT_DESC])->one();
proposalExpect($created && ($created->data['event'] ?? null) === ProposalFeed::EVENT_CREATED
    && ProposalFeed::eventNames(CmsProposal::KIND_PRESENTATION)[ProposalFeed::EVENT_CREATED] === 'Презентация создана', 'The feed says "Презентация создана".');

foreach (['javascript:alert(1)', 'ftp://skeeks.com/file', 'skeeks.com/works', 'https://user:pass@evil.example/', 'https://skeeks.com/a b'] as $bad) {
    $wrong = new CmsProposal(['cms_site_id' => 1, 'kind' => CmsProposal::KIND_PRESENTATION, 'cms_lead_id' => 21, 'name' => 'Плохой адрес', 'target_url' => $bad]);
    proposalExpect(!$wrong->validate() && $wrong->hasErrors('target_url'), 'Not a page of http(s): '.$bad);
}
$noPage = new CmsProposal(['cms_site_id' => 1, 'kind' => CmsProposal::KIND_PRESENTATION, 'cms_lead_id' => 21, 'name' => 'Без страницы']);
proposalExpect(!$noPage->validate() && $noPage->hasErrors('target_url'), 'A presentation needs its page.');
$other = new CmsProposal(['cms_site_id' => 1, 'kind' => CmsProposal::KIND_PRESENTATION, 'cms_lead_id' => 21, 'name' => 'Чужой сайт', 'target_url' => 'http://example.org/page']);
proposalExpect($other->validate(), 'Any domain is allowed: the page is saved by an employee.');
$presentation->kind = CmsProposal::KIND_PROPOSAL;
proposalExpect(!$presentation->validate() && $presentation->hasErrors('kind'), 'The kind is chosen once.');
$presentation->refresh();
proposalExpect(!isset(CmsProposal::kindStatuses(CmsProposal::KIND_PRESENTATION)[CmsProposal::STATUS_ACCEPTED]), 'A presentation is never accepted.');

// 15. Sending: the manager's text, "Посмотреть", no files and no version.
$sendable = new ProposalSendable($presentation);
proposalExpect($sendable->getSendTitle() === 'Сайты для магазинов плитки' && str_contains($sendable->getSendText(), 'примеры наших сайтов')
    && $sendable->getSendButton() === 'Посмотреть' && $sendable->getSendFiles() === [], 'The letter is the text of the manager and the button "Посмотреть".');
$presentation->message = null;
proposalExpect(str_contains($sendable->getSendText(), '«Сайты для магазинов плитки»'), 'Without the text the letter greets and names the page.');
$presentation->refresh();

$context = new SendContext('proposal', (int)$presentation->id, 'email', 'lead21@example.com', $employee);
$sendable->createSendLink($context);
$sendable->afterSend($context, new CmsCommMessage(['id' => 9101]));
$pSend = $context->ref;
$presentation->refresh();
proposalExpect($pSend instanceof CmsProposalSend && $pSend->cms_proposal_version_id === null && (int)$pSend->cms_comm_message_id === 9101, 'A sending of a presentation has no version.');
proposalExpect($presentation->status === CmsProposal::STATUS_SENT, 'A sent presentation is "Отправлено".');
$sentLog = CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 21])->orderBy(['id' => SORT_DESC])->one();
proposalExpect(($sentLog->data['event'] ?? null) === ProposalFeed::EVENT_SENT && !array_key_exists('version', $sentLog->data), 'The feed says "Презентация отправлена" without a version.');

$expiredPresentation = new CmsProposal(['cms_site_id' => 1, 'kind' => CmsProposal::KIND_PRESENTATION, 'cms_lead_id' => 21, 'name' => 'Старая', 'target_url' => 'https://skeeks.com/', 'valid_until' => time() - 60]);
proposalExpect($expiredPresentation->save(), 'An expired presentation must save.');
try {
    (new ProposalSendable($expiredPresentation))->createSendLink(new SendContext('proposal', (int)$expiredPresentation->id, 'email', 'a@b.c', $employee));
    proposalExpect(false, 'An expired presentation must not be sent.');
} catch (SendException $e) {
    proposalExpect(isset($e->errors['sendable']), 'The refusal names the reason.');
}

// 16. The transition: the page of the proposal with marks; marks already in the address stay.
$target = PresentationLinks::targetUrl($pSend);
proposalExpect($target === 'https://skeeks.com/works/sfery/plitka?utm_source=newsletter&utm_medium=email&utm_campaign=proposal-'.$presentation->number.'#cases',
    'Marks for Metrika are added, the employee\'s utm_source stays, the fragment stays at the end: '.$target);
proposalExpect(PresentationLinks::addUtm('https://skeeks.com/solutions/plitka', ['utm_source' => 'crm', 'utm_medium' => 'sms'])
    === 'https://skeeks.com/solutions/plitka?utm_source=crm&utm_medium=sms', 'A page without a query gets the marks.');
proposalExpect(PresentationLinks::addUtm('https://skeeks.com/?a=1&UTM_MEDIUM=x', ['utm_medium' => 'sms', 'utm_campaign' => 'proposal-5'])
    === 'https://skeeks.com/?a=1&UTM_MEDIUM=x&utm_campaign=proposal-5', 'An existing mark is kept whatever its case.');

// 17. Link checks: seconds after sending, mail networks, automated browsers.
$views = new ProposalViews();
$notifies = static fn() => (int)CmsWebNotify::find()->andWhere(['model_code' => CmsProposal::class, 'model_id' => (int)$presentation->id])->count();
proposalExpect($views->record($pSend, $chrome, '8.8.4.4') === ProposalViews::RESULT_CHECK, 'An opening seconds after sending is a link check.');
CmsProposalSend::updateAll(['created_at' => time() - 600], ['id' => (int)$pSend->id]);
$pSend->refresh();
proposalExpect($views->record($pSend, $chrome, '77.88.21.11') === ProposalViews::RESULT_CHECK, 'A network of a mail service is a link check.');
proposalExpect($views->record($pSend, $chrome, '9.9.9.9', false, ['webdriver' => '1', 'languages' => '2']) === ProposalViews::RESULT_CHECK, 'An automated browser is a link check.');
proposalExpect($views->record($pSend, 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 BingPreview/1.0b', '9.9.9.10') === ProposalViews::RESULT_BOT,
    'A preview robot stays a robot.');
$pSend->refresh();
$presentation->refresh();
proposalExpect((int)$pSend->views_count === 0 && !$pSend->first_viewed_at && $presentation->status === CmsProposal::STATUS_SENT && $notifies() === 0
    && CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$presentation->id])->count() == 0, 'A link check gives no view, no status, no notification and no task.');
proposalExpect(CmsProposalView::find()->andWhere(['cms_proposal_send_id' => (int)$pSend->id])->andWhere(['not', ['check_reason' => null]])->count() == 3,
    'Link checks are kept in the history with their reason.');
$checkedLogs = array_filter(CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_id' => 21])->all(), static fn($l) => ($l->data['event'] ?? '') === ProposalFeed::EVENT_CHECKED);
proposalExpect(count($checkedLogs) === 1, 'The feed says "возможно, проверка ссылок" once per sending.');

// 18. The rule "задача при первом открытии": one task while it is open.
proposalExpect($views->record($pSend, $chrome, '10.0.0.5') === ProposalViews::RESULT_FIRST, 'A person opens the presentation.');
$presentation->refresh();
proposalExpect($presentation->status === CmsProposal::STATUS_OPENED && $notifies() === 1, 'The first view: "Открыто" and a notification.');
$links = CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$presentation->id])->with('task')->all();
proposalExpect(count($links) === 1 && (int)$links[0]->cms_proposal_send_id === (int)$pSend->id, 'The first view gives a task linked to the proposal and the sending.');
$task = $links[0]->task;
proposalExpect($task->name === 'Позвонить: смотрел Презентацию №'.$presentation->number.' — Без компании' && (int)$task->executor_id === 1
    && (int)$task->created_by === 1 && $task->status === CmsTask::STATUS_ACCEPTED && (int)$task->cms_lead_id === 21 && !$task->cms_company_id,
    'The call task is the responsible employee\'s own, accepted, of the lead: '.$task->name);
proposalExpect((int)$task->plan_start_at >= time() + 30 * 60 && str_contains((string)$task->description, 'презентацию'), 'The task starts no sooner than the task rule allows.');
proposalExpect($views->record($pSend, $chrome, '10.0.0.6') === ProposalViews::RESULT_COUNTED, 'Another view counts.');

$context2 = new SendContext('proposal', (int)$presentation->id, 'sms', '79990001122', $employee);
$sendable->createSendLink($context2);
$sendable->afterSend($context2, new CmsCommMessage(['id' => 9102]));
$pSend2 = $context2->ref;
CmsProposalSend::updateAll(['created_at' => time() - 600], ['id' => (int)$pSend2->id]);
$pSend2->refresh();
proposalExpect($views->record($pSend2, $chrome, '10.0.0.7') === ProposalViews::RESULT_FIRST, 'The first view of the second sending.');
proposalExpect(CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$presentation->id])->count() == 1, 'No second task while the first one is open.');

// A done task: the next first view makes a new one.
CmsTask::updateAll(['status' => CmsTask::STATUS_READY], ['id' => (int)$task->id]);
$context3 = new SendContext('proposal', (int)$presentation->id, 'email', 'lead21@example.com', $employee);
$sendable->createSendLink($context3);
$sendable->afterSend($context3, new CmsCommMessage(['id' => 9103]));
CmsProposalSend::updateAll(['created_at' => time() - 600], ['id' => (int)$context3->ref->id]);
$context3->ref->refresh();
$views->record($context3->ref, $chrome, '10.0.0.8');
proposalExpect(CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$presentation->id])->count() == 2, 'After the task is done, a new first view makes a new task.');

// Off by the setting of the site.
$settings = new ProposalSettings();
$settings->setOverride(Component::OVERRIDE_DEFAULT);
$settings->taskOnFirstView = 0;
proposalExpect($settings->save(true, ['taskOnFirstView']), 'The setting saves.');
ProposalSettings::reset();
proposalExpect(!ProposalSettings::taskOnFirstView(1), 'The rule is off.');
CmsTask::updateAll(['status' => CmsTask::STATUS_READY], ['executor_id' => 1]);
$context4 = new SendContext('proposal', (int)$presentation->id, 'email', 'lead21@example.com', $employee);
$sendable->createSendLink($context4);
$sendable->afterSend($context4, new CmsCommMessage(['id' => 9104]));
CmsProposalSend::updateAll(['created_at' => time() - 600], ['id' => (int)$context4->ref->id]);
$context4->ref->refresh();
proposalExpect($views->record($context4->ref, $chrome, '10.0.0.9') === ProposalViews::RESULT_FIRST
    && CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$presentation->id])->count() == 2, 'With the rule off the first view makes no task.');
$settings->taskOnFirstView = 1;
$settings->save(true, ['taskOnFirstView']);
ProposalSettings::reset();

// When to call: today in working hours, otherwise the nearest working period.
$schedule = [
    ['day' => ['1', '2', '3', '4', '5'], 'startHour' => '09', 'startMinutes' => '00', 'endHour' => '13', 'endMinutes' => '00'],
    ['day' => ['1', '2', '3', '4', '5'], 'startHour' => '14', 'startMinutes' => '00', 'endHour' => '18', 'endMinutes' => '00'],
];
$at = static fn(string $time) => strtotime($time);
proposalExpect(ProposalTasks::dueAt($schedule, $at('2026-10-21 10:00')) === $at('2026-10-21 10:35'), 'Wednesday 10:00: today in half an hour.');
proposalExpect(ProposalTasks::dueAt($schedule, $at('2026-10-21 12:40')) === $at('2026-10-21 14:00'), 'Before the lunch break: after it.');
proposalExpect(ProposalTasks::dueAt($schedule, $at('2026-10-23 17:45')) === $at('2026-10-26 09:00'), 'Friday evening: Monday morning.');
proposalExpect(ProposalTasks::dueAt($schedule, $at('2026-10-24 11:00')) === $at('2026-10-26 09:00'), 'Saturday: Monday morning.');
proposalExpect(ProposalTasks::dueAt(null, $at('2026-10-21 07:00')) === $at('2026-10-21 09:00'), 'Without a schedule: Mon–Fri 9–18.');

// 19. A presentation for everybody: no client of its own, the client of every sending.
$shared = new CmsProposal(['cms_site_id' => 1, 'kind' => CmsProposal::KIND_PRESENTATION, 'responsible_id' => 1,
    'name' => 'Решение для магазинов плитки', 'target_url' => 'https://skeeks.com/solutions/plitka']);
proposalExpect($shared->save(), 'A presentation without a client must save: '.json_encode($shared->errors, JSON_UNESCAPED_UNICODE));
proposalExpect($shared->isShared && !$presentation->isShared && CmsProposal::find()->shared()->select('id')->column() == [(int)$shared->id],
    'A presentation without a client is one for everybody.');
$sharedKp = new CmsProposal(['cms_site_id' => 1, 'name' => 'КП без клиента']);
proposalExpect(!$sharedKp->validate() && $sharedKp->hasErrors('cms_lead_id'), 'A КП still needs its client.');
proposalExpect(CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE])->andWhere(['like', 'data', '"id":'.(int)$shared->id.','])->count() == 0,
    'Creating it writes nobody\'s feed.');

$sharedSendable = new ProposalSendable($shared);
try {
    $sharedSendable->createSendLink(new SendContext('proposal', (int)$shared->id, 'email', 'stranger@example.com', $employee));
    proposalExpect(false, 'A presentation for everybody needs a client of the CRM.');
} catch (SendException $e) {
    proposalExpect(isset($e->errors['sendable']) && CmsProposalSend::find()->andWhere(['cms_proposal_id' => (int)$shared->id])->count() == 0, 'Without a CRM record nothing is sent.');
}
$sharedSends = [];
foreach ([20 => 'lead20@example.com', 21 => 'lead21@example.com'] as $leadId => $address) {
    $ctx = new SendContext('proposal', (int)$shared->id, 'email', $address, $employee);
    $ctx->record = \skeeks\cms\models\CmsLead::findOne($leadId);
    $sharedSendable->createSendLink($ctx);
    $sharedSendable->afterSend($ctx, new CmsCommMessage(['id' => 9200 + $leadId]));
    CmsProposalSend::updateAll(['created_at' => time() - 600], ['id' => (int)$ctx->ref->id]);
    $ctx->ref->refresh();
    $sharedSends[$leadId] = $ctx->ref;
}
proposalExpect((int)$sharedSends[20]->cms_lead_id === 20 && (int)$sharedSends[21]->cms_lead_id === 21, 'Every sending keeps the client it was sent to.');
$sharedLogs = static fn(int $leadId, string $event) => count(array_filter(
    CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_code' => \skeeks\cms\models\CmsLead::class, 'model_id' => $leadId])->all(),
    static fn($l) => (int)($l->data['id'] ?? 0) === (int)$shared->id && ($l->data['event'] ?? '') === $event));
proposalExpect($sharedLogs(20, ProposalFeed::EVENT_SENT) === 1 && $sharedLogs(21, ProposalFeed::EVENT_SENT) === 1
    && CmsLog::find()->andWhere(['log_type' => CmsProposal::LOG_TYPE, 'model_code' => \skeeks\cms\models\CmsCompany::class, 'model_id' => 10])->andWhere(['like', 'data', '"id":'.(int)$shared->id.','])->count() == 1,
    'The sending is in the feed of its own client (and of the lead\'s company), not of the others.');
proposalExpect(CmsProposal::find()->forRecord(\skeeks\cms\models\CmsLead::findOne(21))->andWhere(['id' => (int)$shared->id])->exists()
    && CmsProposal::find()->forRecord(\skeeks\cms\models\CmsCompany::findOne(10))->andWhere(['id' => (int)$shared->id])->exists()
    && !CmsProposal::find()->forRecord(\skeeks\cms\models\CmsCompany::findOne(11))->andWhere(['id' => (int)$shared->id])->exists(),
    'The card of a client lists the presentations sent to it.');

CmsTask::updateAll(['status' => CmsTask::STATUS_READY], ['executor_id' => 1]);
proposalExpect($views->record($sharedSends[20], $chrome, '10.1.0.20') === ProposalViews::RESULT_FIRST
    && $views->record($sharedSends[21], $chrome, '10.1.0.21') === ProposalViews::RESULT_FIRST, 'Both clients open it.');
$sharedTasks = CmsProposalTask::find()->andWhere(['cms_proposal_id' => (int)$shared->id])->with('task')->orderBy(['id' => SORT_ASC])->all();
proposalExpect(count($sharedTasks) === 2 && (int)$sharedTasks[0]->task->cms_lead_id === 20 && (int)$sharedTasks[0]->task->cms_company_id === 10
    && (int)$sharedTasks[1]->task->cms_lead_id === 21 && str_ends_with($sharedTasks[1]->task->name, '— Без компании'),
    'Every client who opened it gets a call task of their own, while the other one is open.');
proposalExpect($sharedLogs(20, ProposalFeed::EVENT_OPENED) === 1 && $sharedLogs(21, ProposalFeed::EVENT_OPENED) === 1, '"Презентация открыта" is in the feed of the one who opened it.');
$notice = CmsWebNotify::find()->andWhere(['model_code' => CmsProposal::class, 'model_id' => (int)$shared->id])->orderBy(['id' => SORT_DESC])->one();
proposalExpect($notice && str_starts_with($notice->name, 'Без компании: клиент открыл презентацию'), 'The notification says who opened it: '.($notice ? $notice->name : ''));

// 20. "Отправить презентацию" of a card: what this client already got.
$sentToLead = CmsProposalSend::find()->andWhere(['cms_proposal_id' => (int)$shared->id])
    ->andWhere(\skeeks\cms\proposal\helpers\ProposalUi::recordSendCondition(\skeeks\cms\models\CmsLead::findOne(20)))->select('cms_lead_id')->column();
$sentToCompany = CmsProposalSend::find()->andWhere(['cms_proposal_id' => (int)$shared->id])
    ->andWhere(\skeeks\cms\proposal\helpers\ProposalUi::recordSendCondition(\skeeks\cms\models\CmsCompany::findOne(10)))->count();
$sentToOther = CmsProposalSend::find()->andWhere(['cms_proposal_id' => (int)$shared->id])
    ->andWhere(\skeeks\cms\proposal\helpers\ProposalUi::recordSendCondition(\skeeks\cms\models\CmsCompany::findOne(11)))->count();
proposalExpect($sentToLead == [20] && $sentToCompany == 1 && $sentToOther == 0, 'A card sees the sendings to its own client (a company with its leads).');

// 21. The manager's text has the formatting of a letter; SMS gets it without tags.
$shared->message = '<p>Здравствуйте!</p><p><b>Готовое решение</b> для <a href="https://skeeks.com/solutions/plitka">магазинов плитки</a>.</p><script>alert(1)</script>';
proposalExpect($shared->save(), 'A formatted text must save.');
$shared->refresh();
$sharedSendable = new ProposalSendable($shared);
proposalExpect(!str_contains((string)$shared->message, 'script') && str_contains($sharedSendable->getSendHtml(), '<b>Готовое решение</b>'),
    'The text is cleaned like every rich-text field and the letter keeps its formatting.');
proposalExpect(!str_contains($sharedSendable->getSendText(), '<') && str_contains($sharedSendable->getSendText(), 'Готовое решение'), 'SMS gets the text without tags.');
$plain = new CmsProposal(['kind' => CmsProposal::KIND_PRESENTATION, 'message' => "Строка один\nСтрока два"]);
proposalExpect(substr_count($plain->getMessageHtml(), '<') >= 2 && str_contains($plain->getMessageText(), 'Строка два'), 'A text saved before the editor becomes paragraphs.');
