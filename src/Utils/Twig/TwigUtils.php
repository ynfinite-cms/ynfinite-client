<?php

namespace App\Utils\Twig;

use Twig\Environment;
use \Twig\Loader\ArrayLoader;

class TwigUtils
{
    private $data;
    public $twig;
    public $templates;
    public $templateOverrides;
    public $uriData;
    public $standardTemplates;
    public $currentForm;

    public function __construct(
        $twig, 
        $data,
        $templates,
        $templateOverrides,
        $uriData
    ) {
        $this->data = $data;
        $this->twig = $twig;
        $this->templates = $templates;
        $this->templateOverrides = $templateOverrides;
        $this->uriData = $uriData;

        $this->standardTemplates = [
            'article:article' => 'yn/components/article.twig',
            'article:headline' => 'yn/components/article/headline.twig',
            'article:introText' => 'yn/components/article/intro_text.twig',
            'article:text' => 'yn/components/article/text.twig',
            'article:html' => 'yn/components/article/html.twig',
            'article:images' => 'yn/components/article/images.twig',
            'article:video' => 'yn/components/article/video.twig',
            'article:links' => 'yn/components/article/links.twig',
            'article:tableOfContents' => 'yn/components/article/tableOfContents.twig',
            'article:accordions' => 'yn/components/article/accordions.twig',
            'accordions:accordions' => 'yn/components/accordions.twig',
            'images:image' => 'yn/components/image.twig',
            'images:figure' => 'yn/components/figure.twig',
            'link:link' => 'yn/components/link.twig',
            'links:links' => 'yn/components/links.twig',
            'form:form' => 'yn/components/form.twig',
            'video:video' => 'yn/components/video.twig',
            'languages:languageSwitch' => 'yn/components/languageSwitch.twig',
            'login:userDropdown' => 'yn/components/login/userDropdown.twig',
            'form:fields.select' => 'yn/components/form/select.twig',
            'form:fields.radio' => 'yn/components/form/radio.twig',
            'form:fields.checkbox' => 'yn/components/form/checkbox.twig',
            'form:fields.datetime' => 'yn/components/form/datetime-local.twig',
            'form:fields.month' => 'yn/components/form/month.twig',
            'form:fields.date' => 'yn/components/form/date.twig',
            'form:fields.time' => 'yn/components/form/time.twig',
            'form:fields.week' => 'yn/components/form/week.twig',
            'form:fields.color' => 'yn/components/form/color.twig',
            'form:fields.number' => 'yn/components/form/number.twig',
            'form:fields.range' => 'yn/components/form/range.twig',
            'form:fields.text' => 'yn/components/form/text.twig',
            'form:fields.textarea' => 'yn/components/form/textarea.twig',
            'form:fields.tel' => 'yn/components/form/tel.twig',
            'form:fields.url' => 'yn/components/form/url.twig',
            'form:fields.email' => 'yn/components/form/email.twig',
            'form:fields.password' => 'yn/components/form/password.twig',
            'form:fields.file' => 'yn/components/form/file.twig',
            'form:fields.list' => 'yn/components/form/list.twig',
            'form:fields.hidden' => 'yn/components/form/hidden.twig',
            'form:fields.spacer' => 'yn/components/form/spacer.twig',
            'form:fields.description' => 'yn/components/form/description.twig',
            'form:fields.highlight' => 'yn/components/form/highlight.twig',
            'form:fields.basic' => 'yn/components/form/basic.twig',
            'form:fields.categories' => 'yn/components/form/categories.twig',
            'form:fields.distance' => 'yn/components/form/distance.twig',
            'form:fields.team' => 'yn/components/form/team.twig',
            "gdpr:request" => "yn/module/gdpr/request.twig",
            "gdpr:update" => "yn/module/gdpr/update.twig",
            "authors:authors" => "yn/module/authors/authors.twig",
            'listing:pagination' => 'yn/components/pagination.twig',
            'listing:perPageDropdown' => 'yn/components/perPageDropdown.twig',
        ];
    }

    private function getTemplate($name) {
        if(isset($this->templateOverrides[$name])) {
            $template = $this -> templateOverrides[$name];
        } elseif (isset($this -> standardTemplates[$name])) {
            $template = $this -> standardTemplates[$name];
        } else {
            $template = 'null';
        }

        return $template;
    }
    
    private function getSizes($image, $confAlias) {
        $srcset = [];
        $sizesset = [];
        $src = '';

        $sizeConfig = [];

        if (!is_array($confAlias)) {
            $sizeConfig = $this->data['images'][$confAlias] ?? [];
            $sizes = $sizeConfig['sizes'] ?? [];
        } else {
            $sizes = $confAlias;
        }

        if (count($sizes) === 0) {
            $defaultSizesIndex = array_search(
                'true',
                array_column($this->data['images'], 'isDefault')
            );

            if ($defaultSizesIndex !== false) {
                $keys = array_keys($this->data['images']);
                $sizeConfig = $this->data['images'][$keys[$defaultSizesIndex]];
                $sizes = $sizeConfig['sizes'];
            }
        }

        foreach ($sizes as $size) {
            $path = $image['path'] ?? "";

            $attrArray = [];
            if ($size['w'] ?? null) {
                $attrArray[] = 'w=' . $size['w'];
            }
            if ($size['h'] ?? null) {
                $attrArray[] = 'h=' . $size['h'];
            }
            if ($sizeConfig['disableWebp'] ?? null === true) {
                $attrArray[] = 'disableWebp=1';
            }
            $path .= '?' . implode('&', $attrArray);
            $sizeWidth = $size['w'] ?? [] ?: $size['screenSize'] ?? [] ?: $image['dimensions']['width'] ?? [] ?: null;

            if ($size['screenSize'] ?? [] && $sizeWidth ?? []) {
                $srcset[] = $path . ' ' . $sizeWidth . 'w';
                $sizesset[] = '(max-width: ' . $size['screenSize'] . 'px) ' . $sizeWidth . 'px';
            } else {
                $src = $path;
                $srcset[] = $path;

                if ($sizeWidth ?? null) {
                    $sizesset[] = $sizeWidth . 'px';
                }
            }
        }

        if (!$src) {
            $src = $image['path'] ?? "";
        }

        return [
            'src' => $src,
            'srcset' => implode(', ', $srcset),
            'sizes' => implode(', ', $sizesset),
            'height' => $sizes[0]["h"] ?? 0,
            'width' => $sizes[0]["w"] ?? 0
        ];
    }

    private function calculateImageDimensions($image, $sources) {
        $imageHeight = $image["dimensions"]["height"] ?? null;
        $imageWidth = $image["dimensions"]["width"] ?? null;

        if(!$imageHeight || !$imageWidth || ($sources["height"] && $sources["width"])) {
            return array($sources["width"], $sources["height"]);
        }

        if($sources["height"] && $imageHeight) {
            $ratio = $imageHeight / $sources["height"];
            
            $imageHeight = $sources["height"];
            $imageWidth = round($imageWidth / $ratio);
        }

        if($sources["width"] &&  $imageWidth) {
            $ratio = $imageWidth / $sources["width"];
            
            $imageWidth = $sources["width"];
            $imageHeight = round($imageHeight / $ratio);
        }

        return array($imageWidth, $imageHeight);
    }

    public function renderGdprRequestForm($context, $form) {
        return $this->twig->render($this->getTemplate('gdpr:request'), [
            'form' => $form,
            "section" => $context["section"] ?? array(), 
            'templates' => $this->templates,
        ]);        
    }

    public function renderGdprUpdateForm($context, $form) {
        return $this->twig->render($this->getTemplate('gdpr:update'), [
            'form' => $form,
            "section" => $context["section"] ?? array(), 
            "lead" => $context["lead"] ?? array(),
            'templates' => $this->templates,
        ]);        
    }

    public function renderFields(
        $context, 
        $form,
        $section = [],
        $addValues = [],
        $parent = ''
    ) {
        $this->currentForm = $form;

        $groups = [];
        $hiddenFields = [];

        foreach ($form['groups'] as $key => $group) {
            $groups[$key] = ['label' => $group['label'] ?? ""];


            $fieldGrid = [];
            $currentRow = [];
            $currentY = -1;

            foreach ($group["elements"] as $field) {
                $grid = $field['grid'];

                if ($field['type'] === 'hidden') {
                    $hiddenFields[] = $field;
                } else {
                    if (!is_array($fieldGrid[$grid['y']] ?? null)) {
                        $fieldGrid[$grid['y']] = [];
                    }
                    $fieldGrid[$grid['y']][$grid['x']] = $field;
                    ksort($fieldGrid[$grid['y']]);
                }
            }

            ksort($fieldGrid);

            $groups[$key]['fields'] = $fieldGrid;
        }

        return $this->twig->render('yn/components/renderFields.twig', [
            'form' => $form,
            'groups' => $groups,
            'parent' => $parent,
            'hiddenFields' => $hiddenFields,
            "section" => $context["section"],
            'templates' => $this->templates,
            'addValues' => $addValues,
        ]);
    }

    public function renderGroupFieldsByIndex(
		$form,
		$section,
		$groupIndex,
		$addValues = [],
		$parent = ''
	) {
		$this->currentForm = $form;

		$hiddenFields = [];

		$targetGroup = $form['groups'][$groupIndex];

		$groups = ['label' => $targetGroup['label']];

		$fieldGrid = [];
		$currentRow = [];
		$currentY = -1;

		foreach ($targetGroup["elements"] as $field) {
			$grid = $field['grid'];

			if ($field['type'] === 'hidden') {
				$hiddenFields[] = $field;
			} else {
				if (!is_array($fieldGrid[$grid['y']])) {
					$fieldGrid[$grid['y']] = [];
				}
				$fieldGrid[$grid['y']][$grid['x']] = $field;
				ksort($fieldGrid[$grid['y']]);
			}
		}

		ksort($fieldGrid);

		$groups[0]['fields'] = $fieldGrid;

		return $this->twig->render('yn/components/renderFields.twig', [
			'form' => $form,
			'groups' => $groups,
			'parent' => $parent,
			'hiddenFields' => $hiddenFields,
			'section' => $context["section"],
			'templates' => $this->templates,
			'addValues' => $addValues,
		]);
	}

    public function formField(
        $context, 
        $formField,
        $renderWidget = true,
        $renderHint = true,
        $valueOverride = '',
        $parent = ''
    ) {
        if ($parent) {
            $parent = '[' . $parent . '][::count::]';
        }

        $template = $this->getTemplate('form:fields.' . $formField['type']);
        if (!$template) {
            var_dump('form:fields.' . $formField['type']);
            return '<p>Error</p>';
        } else {
            return $this->twig->render($template, [
                'field' => $formField,
                'parent' => $parent,
                'renderWidget' => $renderWidget,
                'renderHint' => $renderHint,
                'form' => $this->currentForm,
                'addValue' => $valueOverride,
            ]);
        }
    }

    public function consents($context, $form) {
        return $this->twig->render('yn/components/form/consents.twig', [
            'form' => $form,
        ]);
    }

    public function pagination($context) {
        return $this->twig->render($this->getTemplate('listing:pagination'), [
            'uriData' => $this->uriData,
        ]);
    }

    public function perPageDropdown($context) {
        return $this->twig->render(
            $this->getTemplate('listing:perPageDropdown'),
            ['uriData' => $this->uriData]
        );
    }

    public function linkPage($context, $pageSlug, $slug = '') {
        $route = $this->data['routes'][$pageSlug] ?? "";
        if($slug && $route) {
            return str_replace('{{alias}}', $slug, $route);
        }
        return $route;
    }

    public function sectionByAlias($context, $alias) {
        $sections = $this->data['sections'];
        foreach ($sections as $key => $section) {
            if ($section['alias'] === $alias) {
                $section['template'] = $this->data['templates'][$section['template']];
                return $section;
            }
        }
        return false;
    }

    public function withVersion($context, $path) {
        if($path){
            $path = trim($path);
            $parsed = parse_url($path);
            $separator = "?";
            if ($parsed["query"] ?? null){
                $separator = "&";
            }
            
            $time = filemtime(getcwd().$parsed["path"]) + 60 * 60 * 2;
            $pathWithVersion = $path.$separator."v=".$time;
            return($pathWithVersion);
        }
    }

// ================================================== OLD PRINT FUNCTIONS ================================================== //

    public function printImage($context, $image, $sizes = [], $classes = "", $nolazy = "") {
        if($nolazy){$noLazy = true;}else{$noLazy = false;};
        return $this->image($context, $image, array('imgConfig' => $sizes, 'classes' => $classes, 'noLazy' => $noLazy));
    }

    public function printFigure($context, $image, $sizes = [], $classes = "", $nolazy = "") {
        if($nolazy){$noLazy = true;}else{$noLazy = false;};
        return $this->figure($context, $image, array('imgConfig' => $sizes, 'classes' => $classes, 'noLazy' => $noLazy));
    }

    public function printLink($context, $link, $classes = "", $params = "") {
        return $this->link($context, $link, array('classes' => $classes, 'params' => $params));
    }

    public function printLinks($context, $links, $classes = "", $params = "") {
        return $this->links($context, $links, array('classes' => $classes, 'params' => $params));
    }

    public function printAccordions($context, $accordions, $classes = "", $params = "") {
        return $this->accordions($context, $accordions, array('classes' => $classes, 'params' => $params));
    }
    
    public function printTeaserText($context, $article, $classes = "", $params = ""){
        return $this->teaserText($context, $article, array('classes' => $classes, 'params' => $params));
    }
    
    public function printCookieSettingsButton() {
        return $this->cookieSettingsButton();
    }

    public function printLoader() {
        return $this->loader();
    }

    public function printString ($context, $data){
        return $this->string($context, $data);
    }

    public function renderArticle($context, $article, $imageConfigAlias = []) {
        return $this->article($context, $article, array('imgConfig' => $imageConfigAlias));
    }

    public function renderArticleComponent($context, $component, $imageConfigAlias = []) {
        return $this->articleComponent($context, $component, array('imgConfig' => $imageConfigAlias));
    }

    public function renderAsTemplate($context, $template, $data = []) {
        return $this->asTemplate($context, $template, array('data' => $data));
    }

// ================================================== NEW GET FUNCTIONS ================================================== //

    public function image($context, $image, $options = []) {
        if($image){
            $sources = $this->getSizes($image, $options['imgConfig'] ?? "");
            $dimensions = $this->calculateImageDimensions($image, $sources);
            
            return $this->twig->render($this->getTemplate('images:image'), [
                'image' => $image,
                'src' => $sources['src'],
                'srcset' => $sources['srcset'],
                'sizes' => $sources['sizes'],
                'width' => intval($dimensions[0]),
                'height' => intval($dimensions[1]),
                'classes' => $options['classes'] ?? "",
                'noLazy' => $options['noLazy'] ?? false,
                'title' => $options['title'] ?? $image['metadata']['title'] ?? "",
                'alt' => $options['alt'] ?? $image['metadata']['alt'] ?? $options['description'] ?? $image['metadata']['description'] ?? "",
                'author' => $image['metadata']['author'] ?? "",
                'filename' => $options['filename'] ?? $image['metadata']['filename'] ?? "",
                'options' => $options,
            ]);
        }
    }

    public function figure($context, $image, $options = []) {
        if($image){
            $sources = $this->getSizes($image, $options['imgConfig'] ?? "");
            $dimensions = $this->calculateImageDimensions($image, $sources);
            
            return $this->twig->render($this->getTemplate('images:figure'), [
                'image' => $image,
                'src' => $sources['src'],
                'srcset' => $sources['srcset'],
                'sizes' => $sources['sizes'],
                'width' => intval($dimensions[0]),
                'height' => intval($dimensions[1]),
                'classes' => $options['classes'] ?? "",
                'noLazy' => $options['noLazy'] ?? "",
                'title' => $options['title'] ?? $image['metadata']['title'] ?? "",
                'description' => $options['description'] ?? $image['metadata']['description'] ?? "",
                'alt' => $options['alt'] ?? $image['metadata']['alt'] ?? $options['description'] ?? $image['metadata']['description'] ?? "",
                'author' => $image['metadata']['author'] ?? "",
                'filename' => $options['filename'] ?? $image['metadata']['filename'] ?? "",
                'options' => $options,
            ]);
        }
    }

    public function link($context, $link, $options = []) {
        if($link){
            return $this->twig->render(
                $this->getTemplate("link:link"),
                ["link" => $link, "classes" => $options['classes'] ?? "", "params" => $options['params'] ?? "", "options" => $options]
            );
        }
    }

    public function links($context, $links, $options = []) {
        if($links){
            return $this->twig->render(
                $this->getTemplate("links:links"),
                ["links" => $links, "classes" => $options['classes'] ?? "", "params" => $options['params'] ?? "", "options" => $options]
            );
        }
    }

    public function accordions($context, $accordions, $options = []) {
        if($accordions){
            return $this->twig->render(
                $this->getTemplate("accordions:accordions"),
                ["accordions" => $accordions, "classes" => $options['classes'] ?? "", "params" => $options['params'] ?? "", "options" => $options]
            );
        }
    }

    public function teaserText($context, $article, $options = []) {
        if($article){
            $intro_text = array_values(array_filter($article, function ($i) {return ($i['type'] == 'introText' or $i['type'] == 'text');}));
            if($intro_text){
                // Filtert alles raus was nicht in <p> Tags steht
                $pattern = '/<p\b[^>]*>(.*?)<\/p\b>/s';
                $foundText = 0;
                $i = 0;
                
                while(!$foundText > 0 && isset($intro_text[$i])){
                    if (!empty($intro_text[$i]['value']) && !preg_match($pattern, $intro_text[$i]['value'])) {
                        $intro_text[$i]['value'] = '<p>' . $intro_text[$i]['value'] . '</p>';
                    }
                    if(isset($intro_text[$i]) && preg_match_all($pattern, $intro_text[$i]['value'], $matches) > 0){
                        $foundText = preg_match_all($pattern, $intro_text[$i]['value'], $matches);
                    }
                    $i++;
                }

                if(!isset($matches[1])){
                    return null;
                }

                $result = '<p>' . implode("\n", $matches[1]) . '</p>';
                return($result);
            }
        }
        return "";
    }

    public function teaserHeading($context, $article, $options = []) {
        if($article && is_array($article)){
            $heading = array_filter($article, function ($i) {return is_array($i) && isset($i['type']) && ($i['type'] == 'headline');});
            if($heading && count($heading) > 0){
                $firstHeading = reset($heading);
                return($firstHeading['value'] ?? '');
            }
        }
        return "";
    }

    public function authorlist($context, $authors, $options = []) {
        if($authors){
             return $this->twig->render(
                $this->getTemplate("authors:authors"),
                ["authors" => $authors]
            );
        }
    }

    public function string($context, $data, $options = []) {
        $intro_title = array();
        foreach($data as $entry){
            if (is_array($entry)){
                if ($entry['value']){
                    $intro_title[]= $entry['prefix'].$entry['value'].$entry['postfix'];
                }
            } else{
                if ($entry){
                    $intro_title[]= $entry . ' ';
                }
            }
        }
        return implode('', $intro_title);
    }

     public function cookieSettingsButton() {
        return $this->twig->render(
            'yn/module/consentManager/settingsButton.twig'
        );
    }

    public function loader() {
        return $this->twig->render(
            'yn/components/loader.twig'
        );
    }

    public function article($context, $article, $options = []) {
        return $this->twig->render($this->getTemplate('article:article'), [
            'article' => $article,
            'imgConfig' => $options['imgConfig'] ?? "",
            'classes' => $options['classes'] ?? "",
            'options' => $options
        ]);
    }

    public function articleComponent($context, $component, $options = []) {
        return $this->twig->render(
            $this->getTemplate('article:' . $component['type']),
            ['component' => $component, 'imgConfig' => $options['imgConfig'] ?? "", 'options' => $options]
        );
    }

    public function asTemplate($context, $template, $options = []) {
        if($template){
            $env = new Environment(new ArrayLoader());
            $template = $env->createTemplate($template);
            if(array_key_exists('data', $options) && !empty($options['data'])) {
                return $env->render($template, $options['data']);
            }
            else {
                return $env->render($template, $context);
            }
        };
    }

    public function form($context, $form, $options = []) {
        $this->currentForm = $form;

        $data = array();
        foreach($form["events"] as $key => $event) {
            $data[] = "data-".strtolower($event["type"]);
        } 

        return $this->twig->render($this->getTemplate('form:form'), [
            'form' => $form,
            'section' => $context["section"] ?? array(),
            'templates' => $this->templates,
            "data" => implode(" ",$data),
        ]);
    }

    public function video($context, $id, $options = []) {
        return $this->twig->render($this->getTemplate('video:video'), [
            'id' => $id,
            'provider' => $options['provider'] ?? "", 
            'noLazy' => $options['noLazy'] ?? false,
            'title' => $options['title'] ?? 'video',
            'height' => $options['height'] ?? '',
            'width' => $options['width'] ?? '',
            'parameter' => $options['parameter'] ?? "",
            'options' => $options
        ]);
    }

    public function userDropdown($context, $options = []) {
        return $this->twig->render($this->getTemplate('login:userDropdown'), [
            'form' => $options['form'] ?? null,
            'links' => $options['links'] ?? null,
            'options' => $options
        ]);
    }

    public function languageSwitch($context, $options = []) {
        return $this->twig->render($this->getTemplate('languages:languageSwitch'), [
            'noPopup' => $options['noPopup'] ?? false,
            'showFlag' => $options['showFlag'] ?? true,
            'options' => $options
        ]);
    }
}
