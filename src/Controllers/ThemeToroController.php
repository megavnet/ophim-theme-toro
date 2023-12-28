<?php

namespace Ophim\ThemeToro\Controllers;

use Backpack\Settings\app\Models\Setting;
use Illuminate\Http\Request;
use App\Models\Actor;
use App\Models\Catalog;
use App\Models\Category;
use App\Models\Director;
use App\Models\Episode;
use App\Models\Movie;
use App\Models\Country as Region;
use App\Models\Tag;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ThemeToroController
{

    public function index(Request $request)
    {
        if ($request['search'] || $request['filter']) {
            $data = Movie::when(!empty($request['filter']['category']), function ($movie) {
                $movie->whereHas('categories', function ($categories) {
                    $categories->where('id', request('filter')['category']);
                });
            })->when(!empty($request['filter']['region']), function ($movie) {
                $movie->whereHas('regions', function ($regions) {
                    $regions->where('id', request('filter')['region']);
                });
            })->when(!empty($request['filter']['year']), function ($movie) {
                $movie->where('publish_year', request('filter')['year']);
            })->when(!empty($request['filter']['type']), function ($movie) {
                $movie->where('type', request('filter')['type']);
            })->when(!empty($request['search']), function ($query) {
                $query->where(function ($query) {
                    $query->where('name', 'like', '%' . request('search') . '%')
                        ->orWhere('origin_name', 'like', '%' . request('search')  . '%');
                })->orderBy('name', 'desc');
            })->when(!empty($request['filter']['sort']), function ($movie) {
                if (request('filter')['sort'] == 'create') {
                    return $movie->orderBy('created_at', 'desc');
                }
                if (request('filter')['sort'] == 'update') {
                    return $movie->orderBy('updated_at', 'desc');
                }
                if (request('filter')['sort'] == 'year') {
                    return $movie->orderBy('publish_year', 'desc');
                }
                if (request('filter')['sort'] == 'view') {
                    return $movie->orderBy('views', 'desc');
                }
            })->paginate(get_theme_option('per_page_limit'));

            return view('themes::themetoro.catalog', [
                'data' => $data,
                'search' => $request['search'],
                'section_name' => "Tìm kiếm phim: $request->search"
            ]);
        }

        $title = Setting::get('site_homepage_title');

        $home_page_slider_poster = Cache::remember('site.movies.home_page_slider_poster', setting('site_cache_ttl', 5 * 60), function () {
            $list = get_theme_option('home_page_slider_poster') ?: [];
            if(empty($list)) return null;
            $data = null;
            $list = $list[0];
            try {
                $movies = query_movies($list);
                $data = [
                    'label' => $list['label'],
                    'data' => $movies,
                ];
            } catch (\Exception $e) {
                Log::error(__CLASS__.'::'.__FUNCTION__.':'.__LINE__.': '. $e->getMessage());
            }
            return $data;
        });
    
        $home_page_slider_thumb = Cache::remember('site.movies.home_page_slider_thumb', setting('site_cache_ttl', 5 * 60), function () {
            $list = get_theme_option('home_page_slider_thumb') ?: [];
            if(empty($list)) return null;
            $data = null;
            $list = $list[0];
            try {
                $movies = query_movies($list);
                $data = [
                    'label' => $list['label'],
                    'data' => $movies,
                ];
            } catch (\Exception $e) {
                Log::error(__CLASS__.'::'.__FUNCTION__.':'.__LINE__.': '. $e->getMessage());
            }
            return $data;
        });
    
        $data = Cache::remember('site.movies.latest', setting('site_cache_ttl', 5 * 60), function () {
            $lists = get_theme_option('latest');
            $data = [];
            foreach ($lists as $list) {
                try {
                    $movies = query_movies($list);
                    $data[] = [
                        'label' => $list['label'],
                        'show_template' => $list['show_template'],
                        'data' => $movies,
                        'link' => $list['show_more_url'] ?: '#',
                    ];
                } catch (\Exception $e) {
                    Log::error(__CLASS__.'::'.__FUNCTION__.':'.__LINE__.': '. $e->getMessage());
                }
            }
            return $data;
        });


        return view('themes::themetoro.index', compact('data', 'title', 'home_page_slider_poster', 'home_page_slider_thumb'));
    }

    public function getMovieOverview(Request $request)
    {
        /** @var Movie */
        $movie = Movie::where('slug', $request->movie)->orWhere('id', $request->movie)->firstOrFail();

        $movie->generateSeoTags();

        $movie->increment('views', 1);
        $movie->increment('views_day', 1);
        $movie->increment('views_week', 1);
        $movie->increment('views_month', 1);

        $movie_related_cache_key = 'movie_related:' . $movie->id;
        $movie_related = Cache::get($movie_related_cache_key, []);
        if(is_null($movie_related) && $movie->categories->count() > 0) {
            $movie_related = $movie->categories[0]->movies()->inRandomOrder()->limit(get_theme_option('movie_related_limit', 10))->get();
            Cache::put($movie_related_cache_key, $movie_related, setting('site_cache_ttl', 5 * 60));
        }

        return view('themes::themetoro.single', [
            'currentMovie' => $movie,
            'title' => $movie->getTitle(),
            'movie_related' => $movie_related
        ]);
    }

    public function getEpisode(Request $request)
    {
        $movie = Movie::where('slug', $request->movie)->orWhere('id', $request->movie)->firstOrFail();
        $movie->load('episodes');

        /** @var Episode */
        $episode_id = $request->id;
        $episode = $movie->episodes->when($episode_id, function ($collection, $episode_id) {
            return $collection->where('id', $episode_id);
        })->firstWhere('slug', $request->episode);

        $server_episodes = $movie->episodes()->where('slug', $episode->slug)->get();

        $episode->generateSeoTags();

        $movie->increment('views', 1);
        $movie->increment('views_day', 1);
        $movie->increment('views_week', 1);
        $movie->increment('views_month', 1);

        $movie_related_cache_key = 'movie_related:' . $movie->id;
        $movie_related = Cache::get($movie_related_cache_key);
        if(is_null($movie_related) && $movie->categories) {
            $movie_related = $movie->categories[0]->movies()->inRandomOrder()->limit(get_theme_option('movie_related_limit', 10))->get();
            Cache::put($movie_related_cache_key, $movie_related, setting('site_cache_ttl', 5 * 60));
        }

        return view('themes::themetoro.episode', [
            'currentMovie' => $movie,
            'movie_related' => $movie_related,
            'episode' => $episode,
            'server_episodes' => $server_episodes,
            'title' => $episode->getTitle()
        ]);
    }

    public function getMovieOfCategory(Request $request)
    {
        /** @var Category */
        $category = Category::fromCache()->find($request->category ?: $request->id);

        if (is_null($category)) abort(404);

        $category->generateSeoTags();

        $movies = $category->movies()->orderBy('created_at', 'desc')->paginate(get_theme_option('per_page_limit'));

        return view('themes::themetoro.catalog', [
            'data' => $movies,
            'category' => $category,
            'title' => $category->seo_title ?: $category->getTitle(),
            'section_name' => "Phim thể loại $category->name"
        ]);
    }

    public function getMovieOfRegion(Request $request)
    {
        /** @var Region */
        $region = Region::fromCache()->find($request->region ?: $request->id);

        if (is_null($region)) abort(404);

        $region->generateSeoTags();

        $movies = $region->movies()->orderBy('created_at', 'desc')->paginate(get_theme_option('per_page_limit'));

        return view('themes::themetoro.catalog', [
            'data' => $movies,
            'region' => $region,
            'title' => $region->seo_title ?: $region->getTitle(),
            'section_name' => "Phim quốc gia $region->name"
        ]);
    }

    public function getMovieOfActor(Request $request)
    {
        /** @var Actor */
        $actor = Actor::fromCache()->find($request->actor ?: $request->id);

        if (is_null($actor)) abort(404);

        $actor->generateSeoTags();

        $movies = $actor->movies()->orderBy('created_at', 'desc')->paginate(get_theme_option('per_page_limit'));

        return view('themes::themetoro.catalog', [
            'data' => $movies,
            'person' => $actor,
            'title' => $actor->getTitle(),
            'section_name' => "Diễn viên $actor->name"
        ]);
    }

    public function getMovieOfDirector(Request $request)
    {
        /** @var Director */
        $director = Director::fromCache()->find($request->director ?: $request->id);

        if (is_null($director)) abort(404);

        $director->generateSeoTags();

        $movies = $director->movies()->orderBy('created_at', 'desc')->paginate(get_theme_option('per_page_limit'));

        return view('themes::themetoro.catalog', [
            'data' => $movies,
            'person' => $director,
            'title' => $director->getTitle(),
            'section_name' => "Đạo diễn $director->name"
        ]);
    }

    public function getMovieOfTag(Request $request)
    {
        /** @var Tag */
        $tag = Tag::fromCache()->find($request->tag ?: $request->id);

        if (is_null($tag)) abort(404);

        $tag->generateSeoTags();

        $movies = $tag->movies()->orderBy('created_at', 'desc')->paginate(get_theme_option('per_page_limit'));
        return view('themes::themetoro.catalog', [
            'data' => $movies,
            'tag' => $tag,
            'title' => $tag->getTitle(),
            'section_name' => "Tags: $tag->name"
        ]);
    }

    public function getMovieOfType(Request $request)
    {
        /** @var Catalog */
        $catalog = Catalog::fromCache()->find($request->type ?: $request->id);

        if (is_null($catalog)) abort(404);

        $catalog->generateSeoTags();

        $cache_key = 'catalog:' . $catalog->id . ':page:' . ($request['page'] ?: 1);
        $movies = Cache::get($cache_key);
        if(is_null($movies)) {
            $value = explode('|', trim($catalog->value));
            [$relation_config, $field, $val, $sortKey, $alg] = array_merge($value, ['', 'is_copyright', 0, 'created_at', 'desc']);
            $relation_config = explode(',', $relation_config);

            [$relation_table, $relation_field, $relation_val] = array_merge($relation_config, ['', '', '']);
            try {
                $movies = Movie::when($relation_table, function ($query) use ($relation_table, $relation_field, $relation_val, $field, $val) {
                    $query->whereHas($relation_table, function ($rel) use ($relation_field, $relation_val, $field, $val) {
                        $rel->where($relation_field, $relation_val)->where(array_combine(explode(",", $field), explode(",", $val)));
                    });
                })->when(!$relation_table, function ($query) use ($field, $val) {
                    $query->where(array_combine(explode(",", $field), explode(",", $val)));
                })
                ->orderBy($sortKey, $alg)
                ->paginate($catalog->paginate);

                Cache::put($cache_key, $movies, setting('site_cache_ttl', 5 * 60));
            } catch (\Exception $e) {}
        }

        return view('themes::themetoro.catalog', [
            'data' => $movies,
            'section_name' => "Danh sách $catalog->name"
        ]);
    }

    public function reportEpisode(Request $request, $movie, $slug)
    {
        $movie = Movie::fromCache()->find($movie)->load('episodes');

        $episode = $movie->episodes->when(request('id'), function ($collection) {
            return $collection->where('id', request('id'));
        })->firstWhere('slug', $slug);

        $episode->update([
            'report_message' => request('message', ''),
            'has_report' => true
        ]);

        return response([], 204);
    }

    public function rateMovie(Request $request, $movie)
    {

        $movie = Movie::fromCache()->find($movie);

        $movie->refresh()->increment('rating_count', 1, [
            'rating_star' => $movie->rating_star +  ((int) request('rating') - $movie->rating_star) / ($movie->rating_count + 1)
        ]);

        return response([], 204);
    }
}
