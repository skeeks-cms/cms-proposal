<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\models\queries;

use skeeks\cms\models\CmsCompany;
use skeeks\cms\models\CmsDeal;
use skeeks\cms\models\CmsLead;
use skeeks\cms\models\CmsUser;
use skeeks\cms\proposal\models\CmsProposal;
use skeeks\cms\proposal\models\CmsProposalSend;
use skeeks\cms\query\CmsActiveQuery;
use skeeks\cms\rbac\CmsManager;
use yii\web\User;

/**
 * Proposals an employee may see: everything for administrators; otherwise
 * those they or their subordinates are responsible for, and those whose
 * client (lead, company, deal) is visible to them through the core
 * forManager() scopes; presentations for everybody (no client) are seen by
 * every employee. Lead proposals are shown in the card of the lead's
 * company through forCompany(); a card also lists the presentations sent
 * to its client.
 */
class CmsProposalQuery extends CmsActiveQuery
{
    /**
     * @param \skeeks\cms\models\CmsUser|null $user explicit employee (jobs, API); the current one by default
     */
    public function forManager($user = null)
    {
        if ($user === null) {
            $user = \Yii::$app->has('user') && !\Yii::$app->user->isGuest ? \Yii::$app->user->identity : null;
            $isAdmin = $user && \Yii::$app->user->can(CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
        } else {
            $isAdmin = \Yii::$app->authManager->checkAccess($user->id, CmsManager::PERMISSION_ROLE_ADMIN_ACCESS);
        }
        if (!$user) {
            return $this->andWhere('0=1');
        }
        if ($isAdmin) {
            return $this;
        }

        $managerIds = [(int)$user->id];
        foreach ((array)$user->subordinates as $subordinate) {
            $managerIds[] = (int)$subordinate->id;
        }
        $table = $this->getPrimaryTableName();
        $managerIds = array_values(array_unique($managerIds));
        $leads = CmsLead::find()->forManager($user)->select(CmsLead::tableName().'.id');
        // A proposal of a deal follows the deal: a client seen in full does not open the proposals
        // of deals hidden from him (CmsLeadQuery::forManager()).
        $ownLeadOrNone = ['or', [$table.'.cms_lead_id' => null], [$table.'.cms_lead_id' => $leads]];
        // A client seen in full, or "своё" for the level "Свои сделки" (CrmAccess::clientRecordCondition()):
        // his proposals (responsible or author), of his deals, of the services he sees.
        $client = \skeeks\cms\crm\CrmAccess::instance()->clientRecordCondition($user, $table);

        return $this->andWhere(['or',
            [$table.'.responsible_id' => $managerIds],
            [$table.'.created_by' => $managerIds],
            [$table.'.cms_lead_id' => $leads],
            ['and', $client, $ownLeadOrNone],
            [$table.'.cms_deal_id' => CmsDeal::find()->forManager($user)->select(CmsDeal::tableName().'.id')],
            // A presentation for everybody is a material of the whole team.
            self::sharedCondition($table),
        ]);
    }

    /** A presentation without a client of its own. */
    public static function sharedCondition(string $table): array
    {
        return ['and',
            [$table.'.kind' => CmsProposal::KIND_PRESENTATION],
            [$table.'.cms_lead_id' => null],
            [$table.'.cms_company_id' => null],
            [$table.'.cms_deal_id' => null],
            [$table.'.cms_user_id' => null],
        ];
    }

    /** Presentations for everybody only. */
    public function shared()
    {
        return $this->andWhere(self::sharedCondition($this->getPrimaryTableName()));
    }

    /** Proposals sent to the client: sendings with a message whose client matches. */
    private static function sentTo(array $condition): \yii\db\ActiveQuery
    {
        return CmsProposalSend::find()->select('cms_proposal_id')->distinct()
            ->andWhere(['not', ['cms_comm_message_id' => null]])
            ->andWhere($condition);
    }

    /** Proposals of a company: its own, of its leads and of its deals, and those sent to any of them. */
    public function forCompany(int $companyId)
    {
        $table = $this->getPrimaryTableName();
        $leads = CmsLead::find()->select('id')->andWhere(['cms_company_id' => $companyId]);
        $deals = CmsDeal::find()->select('id')->andWhere(['cms_company_id' => $companyId]);

        return $this->andWhere(['or',
            [$table.'.cms_company_id' => $companyId],
            [$table.'.cms_lead_id' => $leads],
            [$table.'.cms_deal_id' => $deals],
            [$table.'.id' => self::sentTo(['or', ['cms_company_id' => $companyId], ['cms_lead_id' => $leads], ['cms_deal_id' => $deals]])],
        ]);
    }

    /**
     * Proposals of a CRM card record: a lead, a company (with its leads and
     * deals), a deal or a client person; with the presentations for
     * everybody sent to it.
     */
    public function forRecord($record)
    {
        $table = $this->getPrimaryTableName();
        foreach (['cms_lead_id' => CmsLead::class, 'cms_deal_id' => CmsDeal::class, 'cms_user_id' => CmsUser::class] as $column => $class) {
            if ($record instanceof $class) {
                return $this->andWhere(['or',
                    [$table.'.'.$column => (int)$record->id],
                    [$table.'.id' => self::sentTo([$column => (int)$record->id])],
                ]);
            }
        }
        if ($record instanceof CmsCompany) {
            return $this->forCompany((int)$record->id);
        }

        return $this->andWhere('0=1');
    }

    /** Search by number and name. */
    public function search($word = '')
    {
        $word = trim((string)$word);
        if ($word === '') {
            return $this;
        }
        $table = $this->getPrimaryTableName();
        $number = (string)preg_replace('/^[№#\s]+/u', '', $word);

        return $this->andWhere(ctype_digit($number)
            ? ['or', [$table.'.number' => (int)$number], ['like', $table.'.name', $word]]
            : ['like', $table.'.name', $word]);
    }
}
