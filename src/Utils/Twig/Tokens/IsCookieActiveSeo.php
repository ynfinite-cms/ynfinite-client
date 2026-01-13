<?php

namespace App\Utils\Twig\Tokens;

use App\Utils\Twig\Tokens\IsCookieActiveSeoNode;

class IsCookieActiveSeo extends \Twig\TokenParser\AbstractTokenParser
{

    private $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function parse(\Twig\Token $token)
    {

        $parser = $this->parser;
        $stream = $parser->getStream();
        
        $nameExpr = $parser->parseExpression();

        $stream->expect(\Twig\Token::BLOCK_END_TYPE);
        $body = $parser->subparse([$this, 'decideIfEnd']);
        $tests = [$nameExpr, $body];

        $end = false;
        while (!$end) {
            switch ($stream->next()->getValue()) {
                case 'endIsCookieActiveSeo':
                    $end = true;
                    break;

                default:
                    throw new SyntaxError(sprintf('Unexpected end of template. Twig was looking for the following tags "endIsCookieActiveSeo" to close the "isCookieActiveSeo" block started at line %d).', $lineno), $stream->getCurrent()->getLine(), $stream->getSourceContext());
            }
        }

        $stream->expect(\Twig\Token::BLOCK_END_TYPE);

        return new IsCookieActiveSeoNode(new \Twig\Node\Nodes($tests), $token->getLine());
    }

    public function getTag()
    {
        return 'isCookieActiveSeo';
    }

    public function decideIfEnd(\Twig\Token $token): bool
    {
        return $token->test(['endIsCookieActiveSeo']);
    }
}