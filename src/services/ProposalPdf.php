<?php
/**
 * @link https://cms.skeeks.com/
 * @copyright Copyright (c) 2010 SkeekS
 * @license https://cms.skeeks.com/license/
 */

namespace skeeks\cms\proposal\services;

use skeeks\cms\models\CmsDocumentTemplate;
use skeeks\cms\models\CmsSite;
use skeeks\cms\proposal\models\CmsProposalVersion;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;

/**
 * PDF of a proposal version through mPDF, designed by the site's document
 * template (CmsDocumentTemplate: logo, colors, light or dark theme, footer,
 * page numbers), like the task report and the bill. Without a template the
 * light preset is used.
 *
 * Pictures of the intro and terms are passed to mPDF as reduced local
 * copies (it embeds originals at full size and can exhaust memory); a
 * picture that cannot be read safely is left out. Blocks are written by
 * separate WriteHTML() calls so a long text never hits pcre.backtrack_limit.
 */
class ProposalPdf
{
    /** Document type for CmsDocumentTemplate::findDefault(); falls back to the "all documents" template. */
    public const DOCUMENT_TYPE = 'proposal';

    /** Longest side of a picture copy in the PDF, px. */
    public const IMAGE_BOX = 1400;

    private const CHUNK = '<!--sx-pdf-chunk-->';

    public static function fileName(CmsProposalVersion $version): string
    {
        return 'КП-'.(int)$version->proposal->number.'-версия-'.(int)$version->version.'.pdf';
    }

    /** @return string the PDF bytes */
    public function render(CmsProposalVersion $version): string
    {
        $proposal = $version->proposal;
        $site = $proposal->cmsSite ?: \Yii::$app->skeeks->site;
        $theme = $this->theme($site);

        $tempDir = \Yii::getAlias('@runtime/mpdf');
        if (!is_dir($tempDir)) {
            @mkdir($tempDir, 0777, true);
        }

        $html = \Yii::$app->view->renderFile('@skeeks/cms/proposal/views/pdf/proposal.php', [
            'version'  => $version,
            'proposal' => $proposal,
            'theme'    => $theme,
            'chunk'    => self::CHUNK,
            'intro'    => $this->prepareHtml((string)$version->intro, $tempDir),
            'terms'    => $this->prepareHtml((string)$version->terms, $tempDir),
        ]);

        $mpdf = new \Mpdf\Mpdf([
            'tempDir'       => $tempDir,
            'format'        => 'A4',
            'orientation'   => 'P',
            'margin_top'    => 16,
            'margin_right'  => 16,
            'margin_bottom' => $theme['show_footer'] ? 22 : 16,
            'margin_left'   => 16,
            'margin_footer' => 8,
        ]);
        // The page background of the theme; an @page rule in the CSS would drop the footer.
        $mpdf->SetDefaultBodyCSS('background', (string)ArrayHelper::getValue($theme, 'background_color', '#ffffff'));
        $mpdf->SetTitle($proposal->asText);
        $mpdf->SetAuthor((string)$site->name);
        if ($theme['show_footer']) {
            $footer = trim((string)ArrayHelper::getValue($theme, 'footer_text'));
            if ($footer === '') {
                $footer = trim($site->name.' · '.$site->url, ' ·');
            }
            $mpdf->SetHTMLFooter(
                '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;border-top:1px solid '.Html::encode($theme['border_color'])
                .';color:'.Html::encode($theme['muted_color']).';font-size:8pt;"><tr>'
                .'<td width="80%" style="padding:3mm 0 0;">'.Html::encode($footer).'</td>'
                .'<td width="20%" align="right" style="padding:3mm 0 0;">'.($theme['show_page_numbers'] ? '{PAGENO} / {nbpg}' : '').'</td>'
                .'</tr></table>'
            );
        }

        foreach (explode(self::CHUNK, $html) as $index => $part) {
            if (trim($part) !== '') {
                $mpdf->WriteHTML($part, $index === 0 ? \Mpdf\HTMLParserMode::DEFAULT_MODE : \Mpdf\HTMLParserMode::HTML_BODY);
            }
        }

        return $mpdf->Output('', 'S');
    }

    /**
     * Colors and switches of the site's document template, plus the logo
     * path and the site name. The template model may be absent.
     */
    public function theme(?CmsSite $site): array
    {
        $model = $site ? CmsDocumentTemplate::findDefault((int)$site->id, self::DOCUMENT_TYPE) : null;
        $theme = $model ? $model->themeConfig : CmsDocumentTemplate::preset(CmsDocumentTemplate::THEME_LIGHT);

        $logo = $model && $model->logoStorageFile ? $model->logoStorageFile : null;
        if (!$logo && $site) {
            $logo = ArrayHelper::getValue($theme, 'theme') === CmsDocumentTemplate::THEME_DARK
                ? $site->image
                : ($site->logoLightImage ?: $site->image);
        }
        $theme['logo_src'] = null;
        if ($logo) {
            $path = $logo->getRootSrc();
            $theme['logo_src'] = $path && is_file($path) ? $path : null;
        }
        $theme['company_name'] = $site ? (string)$site->name : '';
        $theme['company_url'] = $site ? (string)$site->url : '';
        $theme['show_footer'] = (bool)ArrayHelper::getValue($theme, 'show_footer', true);
        $theme['show_page_numbers'] = (bool)ArrayHelper::getValue($theme, 'show_page_numbers', true);
        unset($theme['model']);

        return $theme;
    }

    /** Rich text for mPDF: local reduced pictures, chunk markers between top-level blocks. */
    private function prepareHtml(string $html, string $tempDir): string
    {
        if (trim(strip_tags($html, '<img>')) === '') {
            return '';
        }
        $html = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($tempDir) {
            if (!preg_match('/\ssrc\s*=\s*"([^"]+)"/i', $m[0], $src)) {
                return '';
            }
            $path = $this->localImage(html_entity_decode($src[1]), $tempDir);

            return $path ? '<img src="'.Html::encode($path).'" style="max-width:100%;">' : '';
        }, $html);

        // Every top-level block (paragraph, list, heading) may start a new WriteHTML() call.
        $doc = new \DOMDocument();
        $errors = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"><div id="sx-pdf-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($errors);
        $root = $loaded ? $doc->getElementById('sx-pdf-root') : null;
        if (!$root) {
            return $html;
        }
        $parts = [];
        foreach ($root->childNodes as $node) {
            $parts[] = $doc->saveHTML($node);
        }

        return implode(self::CHUNK, $parts);
    }

    /** A reduced copy of a site picture (/uploads/…), or null when it is not a safe local raster image. */
    private function localImage(string $src, string $tempDir): ?string
    {
        $path = parse_url($src, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/uploads/') || str_contains($path, '..')) {
            return null;
        }
        $file = \Yii::getAlias('@frontend/web', false) ?: \Yii::getAlias('@webroot', false);
        $file = $file ? $file.$path : null;
        if (!$file || !is_file($file) || !($info = @getimagesize($file))) {
            return null;
        }
        [$width, $height] = $info;
        $longest = max($width, $height);
        if ($longest <= self::IMAGE_BOX && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            return $file;
        }
        if (!function_exists('imagecreatetruecolor') || !$this->fitsMemory($width, $height)) {
            return null;
        }

        $dir = $tempDir.DIRECTORY_SEPARATOR.'proposal-images';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $target = $dir.DIRECTORY_SEPARATOR.md5($file.'|'.filemtime($file).'|'.filesize($file).'|'.self::IMAGE_BOX).'.jpg';
        if (is_file($target)) {
            return $target;
        }
        $source = @imagecreatefromstring((string)file_get_contents($file));
        if (!$source) {
            return null;
        }
        $scale = min(1, self::IMAGE_BOX / $longest);
        $copy = imagecreatetruecolor(max(1, (int)round($width * $scale)), max(1, (int)round($height * $scale)));
        imagefill($copy, 0, 0, imagecolorallocate($copy, 255, 255, 255));
        imagecopyresampled($copy, $source, 0, 0, 0, 0, imagesx($copy), imagesy($copy), $width, $height);
        $saved = imagejpeg($copy, $target, 85);
        imagedestroy($source);
        imagedestroy($copy);

        return $saved ? $target : null;
    }

    /** About 5 bytes per pixel for the decoded source, the copy and GD overhead. */
    private function fitsMemory(int $width, int $height): bool
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || (int)$limit <= 0) {
            return true;
        }
        $bytes = (int)$limit * (['g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10][strtolower(substr(trim($limit), -1))] ?? 1);

        return $width * $height * 5 + memory_get_usage() < $bytes * 0.8;
    }
}
