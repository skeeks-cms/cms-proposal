<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\assets;

use yii\web\AssetBundle;

/**
 * The proposal card, the content window notes and the "КП" block of CRM
 * cards. Registered only by those pages; colors come from the shared
 * --sx-* theme.
 */
class ProposalAsset extends AssetBundle
{
    public $sourcePath = '@skeeks/cms/proposal/assets/src';

    public $css = ['proposal.css'];
}
