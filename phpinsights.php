<?php

declare(strict_types=1);

use NunoMaduro\PhpInsights\Domain\Insights\CyclomaticComplexityIsHigh;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineFunctions;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenDefineGlobalConstants;
use NunoMaduro\PhpInsights\Domain\Insights\ForbiddenNormalClasses;
use PHP_CodeSniffer\Standards\Generic\Sniffs\Files\LineLengthSniff;
use PhpCsFixer\Fixer\ClassNotation\OrderedClassElementsFixer;
use PhpCsFixer\Fixer\ClassNotation\ProtectedToPrivateFixer;
use PhpCsFixer\Fixer\FunctionNotation\VoidReturnFixer;
use PhpCsFixer\Fixer\Operator\NewWithBracesFixer;
use SlevomatCodingStandard\Sniffs\Commenting\DocCommentSpacingSniff;
use SlevomatCodingStandard\Sniffs\Functions\FunctionLengthSniff;
use SlevomatCodingStandard\Sniffs\Functions\StaticClosureSniff;
use SlevomatCodingStandard\Sniffs\TypeHints\DisallowMixedTypeHintSniff;

return [
    'preset' => 'default',
    'remove' => [
        CyclomaticComplexityIsHigh::class,
        LineLengthSniff::class,
        ProtectedToPrivateFixer::class,
        OrderedClassElementsFixer::class,
        VoidReturnFixer::class,
        NewWithBracesFixer::class,
        DocCommentSpacingSniff::class,
        StaticClosureSniff::class,
        DisallowMixedTypeHintSniff::class,
    ],
    'config' => [
        ForbiddenDefineGlobalConstants::class => [
            'exclude' => ['public/index.php'],
        ],
        ForbiddenDefineFunctions::class => [
            'exclude' => ['database/migrations', 'scripts', 'tests'],
        ],
        ForbiddenNormalClasses::class => [
            'exclude' => ['app/Http/Controllers/DashboardController.php'],
        ],
        FunctionLengthSniff::class => [
            'maxLinesLength' => 20,
            'exclude' => ['database/migrations', 'tests'],
        ],
    ],
];
