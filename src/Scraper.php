<?php

namespace Raulr\GooglePlayScraper;

use Goutte\Client;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use Symfony\Component\DomCrawler\Crawler;
use Raulr\GooglePlayScraper\Exception\RequestException;
use Raulr\GooglePlayScraper\Exception\NotFoundException;

/**
 * @author Raul Rodriguez <raul@raulr.net>
 * @author Droidman 
 */
class Scraper
{
    const BASE_URL = 'https://play.google.com';

    protected $client;
    protected $delay = 1000;
    protected $lastRequestTime;
    protected $lang = 'en';
    protected $country = 'us';

    public function __construct(GuzzleClientInterface $guzzleClient = null)
    {
        $this->client = new Client();
        if ($guzzleClient) {
            $this->client->setClient($guzzleClient);
        }
    }

    public function setDelay($delay)
    {
        $this->delay = intval($delay);
    }

    public function getDelay()
    {
        return $this->delay;
    }

    public function setDefaultLang($lang)
    {
        $this->lang = $lang;
    }

    public function getDefaultLang()
    {
        return $this->lang;
    }

    public function setDefaultCountry($country)
    {
        $this->country = $country;
    }

    public function getDefaultCountry()
    {
        return $this->country;
    }

    public function getCategories()
    {
        $crawler = $this->request('', array(
            'hl' => 'en',
            'gl' => 'us',
        ));

        $collections = $crawler
            ->filter('.child-submenu-link')
            ->reduce(function ($node) {
                return strpos($node->attr('href'), '/store/apps') === 0;
            })
            ->each(function ($node) {
                $href = $node->attr('href');
                $collection = end(explode('/', $href));
                $collection = preg_replace('/\?.*$/', '', $collection);

                return $collection;
            });
        $collections = array_unique($collections);

        return $collections;
    }

    public function getCollections()
    {
        return array(
            'topselling_free',
            'topselling_paid',
            'topselling_new_free',
            'topselling_new_paid',
            'topgrossing',
            'movers_shakers',
        );
    }

    public function getApp($id, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;

        $params = array(
            'id' => $id,
            'hl' => $lang,
            'gl' => $country,
        );
        $crawler = $this->request('details', $params);

        $info = array();
        $info['id'] = $id;
        $info['url'] =substr($crawler->filter('[itemprop="url"]')->attr('content'), 0, strpos($crawler->filter('[itemprop="url"]')->attr('content'), "&"));
       $info['image'] = str_replace(["=s180"], "=s150", $crawler->filter('[itemprop="image"]')->attr('src'));
        $info['title'] = $crawler->filter('[itemprop="name"] > span')->text();
       $info['author'] = $crawler->filter('[class="hrTbp R8zArc"]')->text();
        $info['screenshots'] = $crawler->filter('[jscontroller="jt8Aqb"]')->each(function ($node) {       
            return  substr($node->filter('img')->attr('src'), 0, strpos($node->filter('img')->attr('src'), "="));
        });
        $info['author_link'] = $this->getAbsoluteUrl($crawler->filter('[class="hrTbp R8zArc"]')->attr('href'));
        
        $info['categories'] = $crawler->filter('[itemprop="genre"]')->each(function ($node) {
            return $node->text();
        });
        $price = $crawler->filter('[itemprop="offers"] > [itemprop="price"]')->attr('content');
       $info['price'] = $price == '0' ? null : $price;
        $desc = $this->cleanDescription($crawler->filter('[itemprop="description"]>content>div'));
         $info['description'] = $desc['text']; 
        $info['description_html'] =  $desc['html'];     
        
        $ratingNode = $crawler->filter('[class="BHMmbe"]');
        if ($ratingNode->count()) {
            $rating = floatval($ratingNode->text());
        } else {
            $rating = 0.0;
        }
        $info['rating'] = $rating;
        $votesNode = $crawler->filter('[class="AYi5wd TBRnV"]>span');
        if ($votesNode->count()) {
            $votes = intval(str_replace(',', '', $votesNode->text()));
        } else {
            $votes = 0;
        }
        $info['votes'] = $votes;
        
        $info['last_updated'] = trim($crawler->filter('[class="htlgb"]')->text());
      
       $sizeNode = $crawler->filter('[class="htlgb"]')->eq(2);
        if ($sizeNode->count()) {
            $size = trim($sizeNode->text());
        } else {
            $size = null;
        }
        $info['size'] = $size;
        
        $downloadsNode = $crawler->filter('[class="htlgb"]')->eq(4);
        if ($downloadsNode->count()) {
            $downloads = trim($downloadsNode->text());
        } else {
            $downloads = null;
        }
        $info['downloads'] = $downloads;
         
        $versionNode = $crawler->filter('[class="htlgb"]')->eq(6);
        if ($versionNode->count()) {
            $version = trim($versionNode->text());
        } else {
            $version = null;
        }
        $info['version'] = $version;
        $info['supported_os'] = trim($crawler->filter('[class="htlgb"]')->eq(8)->text());
        $info['content_rating'] = $crawler->filter('[class="htlgb"]>div')->eq(6)->text();
        $whatsneNode = $crawler->filter('[jsname="bN97Pc"]>content')->last();
        if ($whatsneNode->count()) {
            $info['whatsnew'] = implode("\n", $whatsneNode->each(function ($node) {
                return $node->text();
            }));
        } else {
            $info['whatsnew'] = null;
        }
    $videoNode = $crawler->filter('[jsname="WR0adb"]');
        if ($videoNode->count()) {
            $youtubeUrl = substr($videoNode->filter('[jsname="pWHZ7d"]')->attr('data-trailer-url'), 0, strpos($videoNode->filter('[jsname="pWHZ7d"]')->attr('data-trailer-url'), "?"));
             $info['video_link'] ='https://www.youtube.com/watch?v='.pathinfo( $youtubeUrl , PATHINFO_BASENAME); 
            $info['video_image'] = $this->getAbsoluteUrl($videoNode->filter('img')->attr('src'));
        } else {
            $info['video_link'] = null;
            $info['video_image'] = null;
        }

        return $info;
    }

    public function getApps($ids, $lang = null, $country = null)
    {
        $ids = (array) $ids;
        $apps = array();

        foreach ($ids as $id) {
            $apps[$id] = $this->getApp($id, $lang, $country);
        }

        return $apps;
    }

    public function getListChunk($collection, $category = null, $start = 0, $num = 60, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;

        if (!is_int($start)) {
            throw new \InvalidArgumentException('"start" must be an integer');
        }
        if ($start < 0 || $start > 500) {
            throw new \RangeException('"start" must be a number between 0 and 500');
        }
        if (!is_int($num)) {
            throw new \InvalidArgumentException('"num" must be an integer');
        }
        if ($num < 0 || $num > 120) {
            throw new \RangeException('"num" must be a number between 0 and 120');
        }

        $path = array();
        if ($category) {
            array_push($path, 'category', $category);
        }
        array_push($path, 'collection', $collection);
        $params = array(
            'hl' => $lang,
            'gl' => $country,
            'start' => $start,
            'num' => $num,
        );
        $crawler = $this->request($path, $params);

        $apps = $crawler->filter('.card')->each(function ($node) {
            $app = array();
            $app['id'] = $node->attr('data-docid');
            $app['url'] = self::BASE_URL.$node->filter('a')->attr('href');
            $app['title'] = $node->filter('a.title')->attr('title');
            $app['image'] = $node->filter('img.cover-image')->attr('data-cover-large');
            $app['author'] = $node->filter('a.subtitle')->attr('title');
            $ratingNode = $node->filter('.current-rating');
            if (!$ratingNode->count()) {
                $rating = 0.0;
            } elseif (preg_match('/\d+(\.\d+)?/', $node->filter('.current-rating')->attr('style'), $matches)) {
                $rating = floatval($matches[0]) * 0.05;
            } else {
                throw new \RuntimeException('Error parsing rating');
            }
            $app['rating'] = $rating;
            $priceNode = $node->filter('.display-price');
            if (!$priceNode->count()) {
                $price = null;
            } elseif (!preg_match('/\d/', $priceNode->text())) {
                $price = null;
            } else {
                $price = $priceNode->text();
            }
            $app['price'] = $price;

            return $app;
        });

        return $apps;
    }

    public function getList($collection, $category = null, $lang = null, $country = null)
    {
        $lang = $lang === null ? $this->lang : $lang;
        $country = $country === null ? $this->country : $country;
        $start = 0;
        $num = 60;
        $apps = array();
        $appsChunk = array();

        do {
            $appsChunk = $this->getListChunk($collection, $category, $start, $num, $lang, $country);
            $apps = array_merge($apps, $appsChunk);
            $start += $num;
        } while (count($appsChunk) == $num && $start <= 500);

        return $apps;
    }

    public function getDetailListChunk($collection, $category = null, $start = 0, $num = 60, $lang = null, $country = null)
    {
        $apps = $this->getListChunk($collection, $category, $start, $num, $lang, $country);
        $ids = array_map(function ($app) {
            return $app['id'];
        }, $apps);

        return $this->getApps($ids);
    }

    public function getDetailList($collection, $category = null, $lang = null, $country = null)
    {
        $apps = $this->getList($collection, $category, $lang, $country);
        $ids = array_map(function ($app) {
            return $app['id'];
        }, $apps);

        return $this->getApps($ids);
    }

    protected function request($path, array $params = array())
    {
        // handle delay
        if (!empty($this->delay) && !empty($this->lastRequestTime)) {
            $currentTime = microtime(true);
            $delaySecs = $this->delay / 1000;
            $delay = max(0, $delaySecs - $currentTime + $this->lastRequestTime);
            usleep($delay * 1000000);
        }
        $this->lastRequestTime = microtime(true);

        if (is_array($path)) {
            $path = implode('/', $path);
        }
        $path = ltrim($path, '/');
        $path = rtrim('/store/apps/'.$path, '/');
        $url = self::BASE_URL.$path;
        $query = http_build_query($params);
        if ($query) {
            $url .= '?'.$query;
        }
        $crawler = $this->client->request('GET', $url);
        $status_code = $this->client->getResponse()->getStatus();
        if ($status_code == 404) {
            throw new NotFoundException('Requested resource not found');
        } elseif ($status_code != 200) {
            throw new RequestException(sprintf('Request failed with "%d" status code', $status_code), $status_code);
        }

        return $crawler;
    }

    protected function getAbsoluteUrl($url)
    {
        $urlParts = parse_url($url);
        $baseParts = parse_url(self::BASE_URL);
        $absoluteParts = array_merge($baseParts, $urlParts);

        $absoluteUrl = $absoluteParts['scheme'].'://'.$absoluteParts['host'];
        if (isset($absoluteParts['path'])) {
            $absoluteUrl .= $absoluteParts['path'];
        } else {
            $absoluteUrl .= '/';
        }
        if (isset($absoluteParts['query'])) {
            $absoluteUrl .= '?'.$absoluteParts['query'];
        }
        if (isset($absoluteParts['fragment'])) {
            $absoluteUrl .= '#'.$absoluteParts['fragment'];
        }

        return $absoluteUrl;
    }

    protected function cleanDescription(Crawler $descriptionNode)
    {
        $descriptionNode->filter('a')->each(function ($node) {
            $domElement = $node->getNode(0);
            $href = $domElement->getAttribute('href');
            while (strpos($href, 'https://www.google.com/url?q=') === 0) {
                $parts = parse_url($href);
                parse_str($parts['query'], $query);
                $href = $query['q'];
            }
            $domElement->setAttribute('href', $href);
        });
        $html = $descriptionNode->html();
        $text = trim($this->convertHtmlToText($descriptionNode->getNode(0)));

        return array(
            'html' => $html,
            'text' => $text,
        );
    }

    protected function convertHtmlToText(\DOMNode $node)
    {
        if ($node instanceof \DOMText) {
            $text = preg_replace('/\s+/', ' ', $node->wholeText);
        } else {
            $text = '';

            foreach ($node->childNodes as $childNode) {
                $text .= $this->convertHtmlToText($childNode);
            }

            switch ($node->nodeName) {
                case 'h1':
                case 'h2':
                case 'h3':
                case 'h4':
                case 'h5':
                case 'h6':
                case 'p':
                case 'ul':
                case 'div':
                    $text = "\n\n".$text."\n\n";
                    break;
                case 'li':
                    $text = '- '.$text."\n";
                    break;
                case 'br':
                    $text = $text."\n";
                    break;
            }

            $text = preg_replace('/\n{3,}/', "\n\n", $text);
        }

        return $text;
    }


}
