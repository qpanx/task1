<?php

namespace App\Console\Commands;

use App\Models\Category;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

#[Signature('products:generate {--count=1000000 : Number of products to append (1-10000000)} {--batch=1000 : Products per insert (1-1000)} {--force : Allow generation in production}')]
#[Description('Append realistic benchmark products using bounded batch inserts')]
class GenerateProducts extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        $validator = Validator::make($this->options(), [
            'count' => ['required', 'integer', 'between:1,10000000'],
            'batch' => ['required', 'integer', 'between:1,1000'],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $count = (int) $this->option('count');
        $batchSize = (int) $this->option('batch');
        $startedAt = hrtime(true);
        $timestamp = time();
        $catalog = [
            ['category' => 'Computers', 'name' => 'Laptop', 'description' => 'Portable computer for gaming, office work and travel.'],
            ['category' => 'Computers', 'name' => 'Monitor', 'description' => 'High resolution display with adjustable stand and vivid colors.'],
            ['category' => 'Audio', 'name' => 'Headphones', 'description' => 'Wireless audio with noise isolation and comfortable ear cushions.'],
            ['category' => 'Audio', 'name' => 'Speaker', 'description' => 'Portable wireless audio for home and outdoor listening.'],
            ['category' => 'Accessories', 'name' => 'Keyboard', 'description' => 'Quiet mechanical keys for a desktop computer or laptop.'],
            ['category' => 'Accessories', 'name' => 'Mouse', 'description' => 'Wireless precision controls with a comfortable ergonomic grip.'],
            ['category' => 'Home', 'name' => 'Coffee Maker', 'description' => 'Programmable brewing with a reusable filter and insulated carafe.'],
            ['category' => 'Outdoors', 'name' => 'Backpack', 'description' => 'Water resistant fabric with a padded laptop compartment.'],
        ];
        $categoryIds = [];

        foreach ($catalog as $item) {
            $categoryIds[$item['category']] ??= Category::query()->firstOrCreate(['name' => 'Benchmark '.$item['category']])->id;
        }

        DB::connection()->disableQueryLog();

        for ($offset = 0; $offset < $count; $offset += $batchSize) {
            $rows = [];
            $size = min($batchSize, $count - $offset);

            for ($index = 0; $index < $size; $index++) {
                $item = $catalog[($offset + $index) % count($catalog)];
                $createdAt = gmdate('Y-m-d H:i:s', $timestamp - random_int(0, 63072000));
                $edition = ['Essential', 'Professional', 'Premium', 'Compact'][random_int(0, 3)];

                $rows[] = [
                    'name' => $edition.' '.$item['name'].' Model '.($offset + $index + 1),
                    'description' => $item['description'].' '.$edition.' edition with a two year warranty.',
                    'price' => number_format(random_int(100, 500000) / 100, 2, '.', ''),
                    'category_id' => $categoryIds[$item['category']],
                    'available' => random_int(1, 10) <= 8,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            DB::table('products')->insert($rows);
        }

        $this->info(sprintf(
            'Generated %d products in %.2f seconds (batch %d; PHP peak memory %.1f MiB).',
            $count, (hrtime(true) - $startedAt) / 1e9, $batchSize, memory_get_peak_usage(true) / 1048576,
        ));

        return self::SUCCESS;
    }
}
