<?php

namespace Database\Seeders;

use App\Models\Cause;
use Illuminate\Database\Seeder;

class OrganizationCauseSeeder extends Seeder
{
    public const CAUSES = [
        'environment' => ['en' => 'Environment', 'fr' => 'Environnement', 'it' => 'Ambiente'],
        'animals' => ['en' => 'Animals', 'fr' => 'Animaux', 'it' => 'Animali'],
        'human-rights' => ['en' => 'Human rights', 'fr' => 'Droits humains', 'it' => 'Diritti umani'],
        'health' => ['en' => 'Health', 'fr' => 'Santé', 'it' => 'Salute'],
        'children' => ['en' => 'Children', 'fr' => 'Enfance', 'it' => 'Infanzia'],
        'disability' => ['en' => 'Disability', 'fr' => 'Handicap', 'it' => 'Disabilità'],
        'migration' => ['en' => 'Migration', 'fr' => 'Migrations', 'it' => 'Migrazioni'],
        'work' => ['en' => 'Work', 'fr' => 'Travail', 'it' => 'Lavoro'],
        'education' => ['en' => 'Education', 'fr' => 'Éducation', 'it' => 'Istruzione'],
        'culture' => ['en' => 'Culture', 'fr' => 'Culture', 'it' => 'Cultura'],
        'territory-urban-planning' => ['en' => 'Territory and urban planning', 'fr' => 'Territoire et urbanisme', 'it' => 'Territorio e urbanistica'],
        'justice' => ['en' => 'Justice', 'fr' => 'Justice', 'it' => 'Giustizia'],
        'gender-equality' => ['en' => 'Gender equality', 'fr' => 'Égalité de genre', 'it' => 'Parità di genere'],
        'older-people' => ['en' => 'Older people', 'fr' => 'Personnes âgées', 'it' => 'Anziani'],
        'poverty' => ['en' => 'Poverty', 'fr' => 'Pauvreté', 'it' => 'Povertà'],
        'international-cooperation' => ['en' => 'International cooperation', 'fr' => 'Coopération internationale', 'it' => 'Cooperazione internazionale'],
        'digital-privacy' => ['en' => 'Digital and privacy', 'fr' => 'Numérique et vie privée', 'it' => 'Digitale e privacy'],
        'sport-leisure' => ['en' => 'Sport and leisure', 'fr' => 'Sport et loisirs', 'it' => 'Sport e tempo libero'],
        'religion-spirituality' => ['en' => 'Religion and spirituality', 'fr' => 'Religion et spiritualité', 'it' => 'Religione e spiritualità'],
        'other' => ['en' => 'Other', 'fr' => 'Autre', 'it' => 'Altro'],
    ];

    public function run(): void
    {
        foreach (self::CAUSES as $slug => $name) {
            Cause::updateOrCreate(['slug' => $slug], ['name' => $name, 'sort_order' => array_search($slug, array_keys(self::CAUSES), true)]);
        }
    }
}
