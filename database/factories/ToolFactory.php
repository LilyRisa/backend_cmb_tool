<?php

namespace Database\Factories;

use App\Models\Tool;
use Illuminate\Database\Eloquent\Factories\Factory;

class ToolFactory extends Factory
{
    protected $model = Tool::class;

    public function definition(): array
    {
        $version = $this->faker->numerify('#.#.#');

        return [
            'name' => 'CMB Core Marketing',
            'slug' => 'cmb-core-marketing-' . str_replace('.', '', $version),
            'type' => 'cmb_core',
            'version' => $version,
            'description' => $this->faker->sentence(),
            'download_url' => "https://cdn.cmbcore.com/cmb-core-marketing/CMBcoreMKT%20Setup%20{$version}.exe",
            'file_size' => '200 MB',
            'sha256' => strtoupper($this->faker->sha256()),
            'changelog' => $this->faker->sentence(),
            'is_active' => true,
            'is_latest' => false,
            'download_count' => 0,
            'released_at' => now(),
        ];
    }
}
