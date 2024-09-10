<?php

namespace App;

use Symfony\Component\DomCrawler\Crawler;

require 'vendor/autoload.php';

class Scrape
{
    private array $products = [];
    private string $baseUrl = 'https://www.magpiehq.com/developer-challenge/smartphones';

    public function run(): void
    {
        $document = ScrapeHelper::fetchDocument($this->baseUrl);
        $pages = $document->filter('#pages div')->children('a')->each(function ($node) {     return $node->text(); });

        foreach ($pages as $page) {
            $document = ScrapeHelper::fetchDocument($this->baseUrl . '?page=' . $page);
            $this->scrapeProductsFromPage($document);
        }

        file_put_contents('output.json',  json_encode(array_values($this->products)));
    }

    private function scrapeProductsFromPage(Crawler $document): void
    {
        $products = $document->filter('#products .product');

        foreach ($products as $productNode) {
            $product = new Crawler($productNode);

            $colours = $product->filter('div span[data-colour]');

            foreach ($colours as $colourNode) {
                $colour = new Crawler($colourNode);
                $colourVariant = $colour->attr('data-colour');
                $item = new Product();

                $this->scrapeProduct($product, $item, $colourVariant);

                $id = $item->title . ' ' . $item->colour;

                $this->products[$id] = $item;
            }
        }

    }

    private function scrapeProduct(Crawler $product, Product $item, string $colour): void
    {
        $item->title = $product->filter('h3')->text();
        $item->price = $this->extractPrice($product);
        $item->image_url = $this->baseUrl . ltrim($product->filter('img')->first()->attr('src'), '.');
        $item->capacityMB = $this->extractCapacity($product);
        $item->colour = $colour;

        $this->extractAndInjectAvailabilityAndShipping($item, $product);

        $item->isAvailable = str_contains($item->availabilityText, "In Stock");
    }

    private function extractDateFromMessage(string $deliveryMessage): ?string
    {
        $pattern = '/\b(?:\d{4}-\d{2}-\d{2}|\d{1,2}(?:th|st|nd|rd)? (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d{4}|tomorrow)\b/i';

        if (preg_match($pattern, $deliveryMessage, $matches)) {
            return date('Y-m-d', strtotime($matches[0]));
        }

        return null;
    }

    private function extractPrice(Crawler $product): ?float
    {
        $price = $product->filter('.my-8.block.text-center.text-lg')->text('empty');

        if ($price === 'empty') {
            return null;
        }

        return (float) ltrim($price, '£');
    }

    private function extractCapacity(Crawler $product): ?int
    {
        $capacity = $product->filter('h3 > .product-capacity')->text('empty');

        if ($capacity === 'empty') {

            return null;
        } else {

            if (str_contains($capacity, 'MB')) {
                return intval($capacity);
            } else {
                return intval($capacity) * 1000;
            }
        }
    }

    private function extractAndInjectAvailabilityAndShipping(Product $item, Crawler $product): void
    {
        $availabilityAndShipping = $product->filter('.my-4.text-sm.block.text-center');
        $availabilityText = $availabilityAndShipping->first()->text('empty');
        $item->availabilityText = $availabilityText !== 'empty'
            ? str_replace('Availability: ', '', $availabilityText)
            : null;

        $item->shippingText = $availabilityAndShipping->count() > 1 ?
            $availabilityAndShipping->last()->text('empty') :
            null;

        if (isset($item->shippingText)) {
            $item->shippingDate = $this->extractDateFromMessage($item->shippingText);
        }

    }


}

$scrape = new Scrape();
$scrape->run();
