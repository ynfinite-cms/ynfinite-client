<?php

namespace App\Utils\Twig\Tokens;

#[\Twig\Attribute\YieldReady]
class GetCookieConsentNode extends \Twig\Node\Node {

    public $twig;
    public $active;
    public $cookie;

    public function __construct($tests, int $lineno, $twig, $cookie = array())
    {
        $nodes = ['tests' => $tests];

        $this->active = $cookie["active"];
        $this->cookie = $cookie["cookie"];
        $this->twig = $twig;

        parent::__construct($nodes, [], $lineno);
    }
    
    public function compile(\Twig\Compiler $compiler): void
    {
        $compiler->addDebugInfo($this);
        if($this->active) {
            $compiler->subcompile($this->getNode('tests'));
        }
        else {
            // $cookieConsent = $this->twig->render("yn/module/cookieManager/consent.twig", array("cookie" => $this->cookie));
            
            $compiler->write('yield from $this->load(\'yn/module/consentManager/consent.twig\', ');
            $compiler->repr($this->getTemplateLine());
            $compiler->write(')->unwrap()->yield(');
            $compiler->repr(array("cookie" => $this->cookie));
            $compiler->write(');');
        }
    }
}