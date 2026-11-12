<?php

declare(strict_types=1);

namespace TarsusIlias\Tests\Support;

use PHPUnit\Framework\TestCase;
use TarsusIlias\Activity\Didactics;
use TarsusIlias\Material\BitType;
use TarsusIlias\Support\Interactions;

/**
 * The counting rules of the partner agreement ("KI-Interaktionen über die
 * API"), one assertion per row of its table.
 */
final class InteractionsTest extends TestCase
{
    public function testAnsweringAQuestionCountsOne(): void
    {
        $this->assertSame(1, Interactions::forGenerate(), 'Frage beantworten: 1');
    }

    public function testTheNextLearningStepAndAgentModeCountOnePerRequest(): void
    {
        $this->assertSame(1, Interactions::forGenerate(), 'Nächsten Lernschritt erstellen: 1');
        $this->assertSame(1, Interactions::forGenerate(false, false), 'Agent-Modus: 1 je Anfrage');
    }

    public function testTheAudioExplainerCountsOnceDespiteAudio(): void
    {
        $this->assertSame(1, Interactions::forAudioExplainer(), 'Ausnahme: Der Audio-Erklärer zählt als eine KI-Interaktion');
    }

    public function testASpokenQuestionOrAnswerCountsTwice(): void
    {
        $this->assertSame(2, Interactions::forGenerate(true), 'Spracheingabe: 2');
        $this->assertSame(2, Interactions::forEvaluation(Didactics::TYPE_OPEN, true), 'Antwort per Audio auf eine offene Frage: 2');
    }

    public function testThinkLongerDoublesAnyRequest(): void
    {
        $this->assertSame(2, Interactions::forGenerate(false, true), 'eine Frage zählt dann 2');
        $this->assertSame(4, Interactions::forGenerate(true, true), 'eine Frage per Sprache 4');
        $this->assertSame(2, Interactions::forEvaluation(Didactics::TYPE_CROSSMULTIPLICATION, false, true));
        $this->assertSame(2, Interactions::forAudioExplainer(true));
    }

    public function testMarkingFreeAnswersCountsOne(): void
    {
        $this->assertSame(1, Interactions::forEvaluation(Didactics::TYPE_OPEN), 'Freie Antwort bewerten: 1');
        $this->assertSame(1, Interactions::forEvaluation(Didactics::TYPE_CROSSMULTIPLICATION), 'Rechenaufgabe bewerten: 1');
        $this->assertSame(1, Interactions::forEvaluation(Didactics::TYPE_TRADECOSTING), 'Trade Costing bewerten: 1');
        $this->assertSame(1, Interactions::forEvaluation('socraticQuestionWidget'), 'sokratische Frage bewerten: 1');
    }

    public function testCorrectingSingleChoiceAndTrueFalseCountsNothing(): void
    {
        $this->assertSame(0, Interactions::forEvaluation(Didactics::TYPE_SINGLECHOICE), 'Single-Choice automatisch korrigieren: 0');
        $this->assertSame(0, Interactions::forEvaluation(Didactics::TYPE_TRUEFALSE), 'Wahr/Falsch automatisch korrigieren: 0');
        $this->assertSame(0, Interactions::forEvaluation(Didactics::TYPE_TRUEFALSE, true, true), 'free stays free, whatever the flags');
        $this->assertTrue(Interactions::isFreeEvaluation(Didactics::TYPE_SINGLECHOICE));
        $this->assertFalse(Interactions::isFreeEvaluation(Didactics::TYPE_OPEN));
    }

    public function testIndexingCountsOnePerRequest(): void
    {
        foreach ([BitType::TEXT, BitType::URL, BitType::DOCUMENT] as $type) {
            $this->assertSame(1, Interactions::forIndexing($type), 'Inhalte indexieren (' . $type . '): 1 je Anfrage');
        }
    }

    public function testIndexingMediaCountsOnePerStartedTwoHours(): void
    {
        $this->assertSame(1, Interactions::forIndexing(BitType::MEDIA), 'unknown running time: the minimum');
        $this->assertSame(1, Interactions::forIndexing(BitType::MEDIA, 1), 'one second: one');
        $this->assertSame(1, Interactions::forIndexing(BitType::MEDIA, 7200), 'exactly two hours: one');
        $this->assertSame(2, Interactions::forIndexing(BitType::MEDIA, 7201), 'two hours and a second: two');
        $this->assertSame(3, Interactions::forIndexing(BitType::MEDIA, 5 * 3600), 'five hours: three');
        $this->assertSame(1, Interactions::forIndexing(BitType::MEDIA, -60), 'a negative figure is unknown');
    }

    public function testManagementAndRetrievalCountNothing(): void
    {
        $this->assertSame(0, Interactions::forManagement(), 'Keine KI-Interaktion: verwalten, Nutzung abfragen, abrufen, auflisten, prüfen, freigeben, bearbeiten, löschen');
    }

    public function testStandardNarrationAndSearchCountOne(): void
    {
        $this->assertSame(1, Interactions::forNarration(), 'Text vertonen (Standard-Audio): 1');
        $this->assertSame(1, Interactions::forSearch(), 'Wissen durchsuchen: 1');
    }
}
