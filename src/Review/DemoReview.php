<?php

declare(strict_types=1);

namespace App\Review;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class DemoReview
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function run(string $scenario, string $locale = 'fr'): ReviewReport
    {
        $t = fn (string $text): string => $this->translator->trans($text, [], locale: $locale);
        if (!in_array($scenario, ['broken', 'fixed', 'uncertain', 'disagreement'], true)) {
            throw new \InvalidArgumentException($t('Cet exemple n’existe pas.'));
        }
        $input = new ReviewInput('https://example.com/before', 'https://example.com/'.$scenario, $t('Le bouton Acheter reste entièrement visible dans la capture mobile.'), $locale);
        $after = match ($scenario) {
            'fixed' => 'fixed', 'uncertain' => 'uncertain', default => 'broken',
        };
        $evidence = new Evidence('/fixtures/before.png', '/fixtures/'.$after.'.png');
        $limitation = $t('Cette capture ne permet pas de vérifier le clic ni le paiement.');
        $inspector = match ($scenario) {
            'fixed' => new Finding(Verdict::Confirmed, $t('Le bouton Acheter est entièrement visible dans les deux captures. La couleur du bandeau a changé, mais elle ne masque pas le bouton.'), 'both', $limitation),
            'uncertain' => new Finding(Verdict::Uncertain, $t('Une fenêtre de chargement recouvre la fiche produit. Je ne peux pas voir le bouton Acheter dans cet état.'), 'after', $t('Il faut une capture de la page après chargement pour conclure.')),
            'disagreement' => new Finding(Verdict::Confirmed, $t('Une zone verte apparaît au bas de la fiche ; elle pourrait correspondre au bouton Acheter.'), 'after', $t('Le libellé du bouton est difficile à distinguer.')),
            default => new Finding(Verdict::Contradicted, $t('Sur la version après, le bandeau fixe recouvre le bouton Acheter. Il était entièrement visible avant.'), 'both', $limitation),
        };
        $critic = match ($scenario) {
            'fixed' => new Finding(Verdict::Confirmed, $t('J’ai revu les deux images : le libellé et les bords du bouton restent visibles. Le changement de couleur n’infirme pas l’affirmation.'), 'both', $limitation),
            'uncertain' => new Finding(Verdict::Uncertain, $t('L’image après montre seulement un état de chargement. L’absence de bouton visible ne suffit pas à prouver une régression.'), 'after', $t('Le contenu final de la page n’est pas observable.')),
            default => new Finding(Verdict::Contradicted, $t('Le bandeau « Livraison offerte » recouvre le bas de la carte après la modification. Le bouton Acheter n’est plus entièrement visible.'), 'after', $limitation),
        };

        return new ReviewReport($input, $evidence, $inspector, $critic, replay: true);
    }
}
