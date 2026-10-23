<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\source;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\models\CmsTask;
use skeeks\cms\proposal\models\CmsProposalTask;
use skeeks\cms\source\RecordOriginInterface;
use skeeks\cms\source\RecordSourceInterface;
use yii\db\ActiveRecord;
use yii\helpers\Html;

/**
 * "Создать КП" from a lead, a company, a deal or a client person: the standard create window
 * of proposals opens with ?source=proposal-client:<kind>.<id>
 * (skeeks/cms RecordSources), for example `proposal-client:lead.15`.
 *
 * One source type for the three kinds, prefixed with the package: a source
 * type has a single provider (Skeeks::$recordSources), and plain `lead`
 * would take the type away from a future "deal from a lead" of the core.
 * The client must be visible to the employee (core forManager() scopes).
 */
class ProposalClientSource implements RecordSourceInterface, RecordOriginInterface
{
    public const TYPE = 'proposal-client';

    public const KINDS = [
        'lead'    => CmsLead::class,
        'company' => CmsCompany::class,
        'deal'    => CmsDeal::class,
        'user'    => CmsUser::class,
    ];

    /** "?source=" value of a CRM record, or null for records proposals cannot be made for. */
    public static function value($record): ?string
    {
        foreach (self::KINDS as $kind => $class) {
            // A client person, never an employee.
            if ($record instanceof $class && !($record instanceof CmsUser && $record->is_worker)) {
                return self::TYPE.':'.$kind.'.'.(int)$record->id;
            }
        }

        return null;
    }

    public function find(string $id)
    {
        if (!preg_match('/^(lead|company|deal|user)\.(\d+)$/', $id, $m) || !\Yii::$app->has('user') || \Yii::$app->user->isGuest) {
            return null;
        }
        $class = self::KINDS[$m[1]];

        $record = $class::find()->forManager()->andWhere([$class::tableName().'.id' => (int)$m[2]])->one();

        return $record instanceof CmsUser && $record->is_worker ? null : $record;
    }

    public function supports(string $modelClass): bool
    {
        return is_a($modelClass, CmsProposal::class, true);
    }

    /** @param CmsLead|CmsCompany|CmsDeal|CmsUser $source */
    public function prefill($source, ActiveRecord $model): void
    {
        /** @var CmsProposal $model */
        $this->enforce($source, $model);
        $model->name = $source instanceof CmsUser ? (string)$source->shortDisplayName : (string)$source->name;
        if ($source instanceof CmsLead && $source->executor_id) {
            $model->responsible_id = (int)$source->executor_id;
        }
        if ($source instanceof CmsDeal && $source->currency_code) {
            $model->currency_code = (string)$source->currency_code;
        }
    }

    /** The client is the source; the form may not swap it for another one. */
    public function enforce($source, ActiveRecord $model): void
    {
        /** @var CmsProposal $model */
        $model->cms_lead_id = $source instanceof CmsLead ? (int)$source->id : null;
        $model->cms_company_id = $source instanceof CmsCompany ? (int)$source->id : null;
        $model->cms_deal_id = $source instanceof CmsDeal ? (int)$source->id : null;
        $model->cms_user_id = $source instanceof CmsUser ? (int)$source->id : null;
    }

    /** The feed entry is written by CmsProposal itself, for every way of creating it. */
    public function created($source, ActiveRecord $model): void
    {
    }

    /**
     * The card of a task made by the rule "задача при первом открытии":
     * "Клиент открыл: КП №7 — …" at the top, linked for those who see it.
     */
    public function origins(ActiveRecord $record): array
    {
        if (!$record instanceof CmsTask) {
            return [];
        }
        $result = [];
        foreach (CmsProposalTask::find()->andWhere(['cms_task_id' => (int)$record->id])->with('proposal')->all() as $link) {
            $proposal = $link->proposal;
            if (!$proposal) {
                continue;
            }
            $visible = CmsProposal::find()->forManager()->andWhere([CmsProposal::tableName().'.id' => (int)$proposal->id])->exists();
            $result[] = [
                'label' => 'Клиент открыл',
                'html' => $visible
                    ? \skeeks\cms\backend\widgets\BackendEntityLink::widget(['controllerId' => '/cmsProposal/admin-cms-proposal', 'modelId' => (int)$proposal->id, 'label' => $proposal->asText])
                    : Html::encode($proposal->numberText),
            ];
        }

        return $result;
    }

    public function describe($source): string
    {
        if ($source instanceof CmsLead) {
            return 'Для лида «'.$source->name.'»';
        }
        if ($source instanceof CmsDeal) {
            return 'Для сделки «'.$source->name.'»';
        }
        if ($source instanceof CmsUser) {
            return 'Для клиента «'.$source->shortDisplayName.'»';
        }

        return 'Для компании «'.$source->name.'»';
    }
}
