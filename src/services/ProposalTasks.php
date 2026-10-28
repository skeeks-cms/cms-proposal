<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\communication\services\Campaigns;
use skeeks\cms\helpers\CmsScheduleHelper;
use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsTask;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\components\ProposalSettings;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\proposal\models\CmsProposalTask;
use yii\helpers\Html;

/**
 * The rule "задача менеджеру при первом открытии", for КП and presentations:
 * when the client opens a sending for the first time (a counted view, not a
 * link check), the responsible employee (otherwise the author) gets a core
 * task "Позвонить: клиент открыл предложение №…" for today, or for the
 * nearest working day outside working hours (the employee's work schedule,
 * Mon–Fri 9–18 when it is empty). The task belongs to the client of the
 * sending (lead, company, client person; a deal through its company) and
 * to the proposal (CmsProposalTask). No task while the employee already has
 * an open task on this proposal (for a presentation for everybody: on this
 * proposal and this client); off by ProposalSettings::$taskOnFirstView.
 */
class ProposalTasks
{
    public const CALL_DURATION = 15 * 60;

    /** Working hours of an employee without a schedule. */
    public const DEFAULT_SCHEDULE = [['day' => [1, 2, 3, 4, 5], 'startHour' => '09', 'startMinutes' => '00', 'endHour' => '18', 'endMinutes' => '00']];

    /** A task may not start sooner than this (CmsTask rule for plan_start_at). */
    public const MIN_LEAD_SECONDS = 31 * 60;

    /** The task made now, or null when the rule is off or an open task exists. */
    public function onFirstView(CmsProposal $proposal, CmsProposalSend $send, ?int $now = null): ?CmsTask
    {
        // A letter of a mailing campaign: the campaign setting decides, otherwise the setting of the section.
        $byCampaign = $send->cms_comm_message_id ? Campaigns::taskOnOpen((int)$send->cms_comm_message_id) : null;
        if ($byCampaign === false || ($byCampaign === null && !ProposalSettings::taskOnFirstView((int)$proposal->cms_site_id))) {
            return null;
        }
        $executorId = ProposalNotifier::recipientId($proposal);
        $executor = $executorId ? CmsUser::find()->isWorker()->andWhere([CmsUser::tableName().'.id' => $executorId])->one() : null;
        if (!$executor) {
            return null;
        }
        if ($this->openTask($proposal, (int)$executor->id, $send)) {
            return null;
        }
        $now = $now ?? time();

        return CmsTask::getDb()->transaction(function () use ($proposal, $send, $executor, $now) {
            $task = new CmsTask();
            $client = $send->clientName !== '' ? $send->clientName : $proposal->getClientName();
            $task->name = mb_substr(($proposal->isPresentation ? 'Позвонить: смотрел Презентацию №' : 'Позвонить: клиент открыл предложение №').(int)$proposal->number
                .($client !== '' ? ' — '.$client : ''), 0, 255);
            $task->executor_id = (int)$executor->id;
            // A task to oneself is accepted at once; the client page has no signed-in user.
            $task->created_by = (int)$executor->id;
            $this->fillClient($task, $send->client ?: $proposal->client);
            $task->plan_start_at = self::dueAt(is_array($executor->work_shedule) ? $executor->work_shedule : null, $now);
            $task->plan_duration = self::CALL_DURATION;
            $task->description = $this->description($proposal, $send, $now);
            if (!$task->save()) {
                throw new \RuntimeException('Не удалось поставить задачу по предложению: '.implode(' ', $task->getFirstErrors()));
            }
            // The blameable behavior writes the signed-in user, none on the client page.
            if ((int)$task->created_by !== (int)$executor->id) {
                $task->updateAttributes(['created_by' => (int)$executor->id]);
            }
            $link = new CmsProposalTask([
                'cms_proposal_id' => (int)$proposal->id,
                'cms_proposal_send_id' => (int)$send->id,
                'cms_task_id' => (int)$task->id,
            ]);
            if (!$link->save()) {
                throw new \RuntimeException('Не удалось связать задачу с предложением.');
            }

            return $task;
        });
    }

    /**
     * An open task of the employee on this proposal; for a presentation for
     * everybody, on this proposal and the client of the sending (another
     * client who opened it needs a call of their own).
     */
    public function openTask(CmsProposal $proposal, int $executorId, ?CmsProposalSend $send = null): ?CmsTask
    {
        $query = CmsTask::find()
            ->innerJoin(['pt' => CmsProposalTask::tableName()], 'pt.cms_task_id = '.CmsTask::tableName().'.id')
            ->andWhere(['pt.cms_proposal_id' => (int)$proposal->id])
            ->andWhere([CmsTask::tableName().'.executor_id' => $executorId])
            ->andWhere([CmsTask::tableName().'.status' => CmsTask::activeStatuses()]);
        if ($proposal->isShared && $send) {
            $query->innerJoin(['ps' => CmsProposalSend::tableName()], 'ps.id = pt.cms_proposal_send_id')
                ->andWhere([
                    'ps.cms_lead_id' => $send->cms_lead_id,
                    'ps.cms_company_id' => $send->cms_company_id,
                    'ps.cms_deal_id' => $send->cms_deal_id,
                    'ps.cms_user_id' => $send->cms_user_id,
                ]);
        }

        return $query->limit(1)->one();
    }

    /**
     * When to call: today within the working hours, otherwise at the start
     * of the nearest working period. Never sooner than MIN_LEAD_SECONDS.
     */
    public static function dueAt(?array $schedule, int $now): int
    {
        $schedule = $schedule ?: self::DEFAULT_SCHEDULE;
        $earliest = (int)(ceil(($now + self::MIN_LEAD_SECONDS) / 300) * 300);
        for ($i = 0; $i <= 31; $i++) {
            $date = date('Y-m-d', strtotime('+'.$i.' day', $now));
            $periods = CmsScheduleHelper::getSchedulesByWorktimeForDate($schedule, $date);
            usort($periods, static fn($a, $b) => (int)$a->start_at <=> (int)$b->start_at);
            foreach ($periods as $period) {
                if ((int)$period->end_at > $earliest) {
                    return max((int)$period->start_at, $earliest);
                }
            }
        }

        return $earliest;
    }

    private function fillClient(CmsTask $task, $client): void
    {
        if ($client instanceof CmsLead) {
            $task->cms_lead_id = (int)$client->id;
            if ($client->cms_company_id) {
                $task->cms_company_id = (int)$client->cms_company_id;
            } elseif ($client->cms_user_id) {
                $task->cms_user_id = (int)$client->cms_user_id;
            }
        } elseif ($client instanceof CmsCompany) {
            $task->cms_company_id = (int)$client->id;
        } elseif ($client instanceof CmsDeal) {
            // Core tasks have no deal: the deal's company holds the task.
            if ($client->cms_company_id) {
                $task->cms_company_id = (int)$client->cms_company_id;
            }
        } elseif ($client instanceof CmsUser) {
            $task->cms_user_id = (int)$client->id;
        }
    }

    private function description(CmsProposal $proposal, CmsProposalSend $send, int $now): string
    {
        $lines = [
            'Клиент открыл '.($proposal->isPresentation ? 'презентацию' : 'КП').' №'.(int)$proposal->number
                .($proposal->name ? ' «'.$proposal->name.'»' : '').' '.\Yii::$app->formatter->asDatetime($now, 'php:d.m.Y в H:i')
                .' ('.mb_strtolower($send->channelName).($send->recipientText !== '' ? ', '.$send->recipientText : '').').',
        ];
        if ($proposal->isPresentation && $proposal->target_url) {
            $lines[] = 'Страница: '.$proposal->target_url;
        }
        $deal = $send->deal ?: $proposal->deal;
        if ($deal) {
            $lines[] = 'Услуга: '.$deal->asText;
        }
        $lead = $send->lead ?: $proposal->lead;
        if ($lead && ($phone = $lead->mainPhone)) {
            $lines[] = 'Телефон: '.$phone->value;
        }
        $lines[] = 'Позвоните, пока интерес свежий.';

        return implode('', array_map(static fn($line) => Html::tag('p', Html::encode($line)), $lines));
    }
}
