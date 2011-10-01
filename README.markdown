# Search Index

* Version: 2.0beta
* Author: [Nick Dunn](http://nick-dunn.co.uk)
* Build Date: 2011-10-01
* Requirements: Symphony 2.2

## Description
Search Index provides an easy way to implement high performance fulltext searching on your Symphony site. By setting filters for each Section in your site you control which entries are indexed and therefore searchable. Frontend search can be implemented either using the Search Index Filter field that allows keyword filtering in data sources, or the included Search Index data source for searching multiple sections at once.

## Usage
1. Add the `search_index` folder to your Extensions directory
2. Enable the extension from the Extensions page
3. Configure indexes from Search Index > Indexes

### 1. Configuring section indexes
After installation navigate to Search Index > Indexes whereupon you will see a list of all sections in your site. Click on a name to configure the indexing criteria for that section. The index editor works the same as the data source editor:

* Only values of fields selected from the **Included Elements** list are used for searching
* **Index Filters** work exactly like data source filters. Use these to ensure only desired entries are indexed

Once saved the "Index" column will display "0 entries" on the Search Indexes page. Select the row and choose "Re-index Entries" from the With Selected menu. When the page reloads you will see the index being rebuilt, one page of results at a time.

Multiple sections can be selected at once for re-indexing.

The page size and speed of refresh can be modified by editing the `re-index-per-page` and `re-index-refresh-rate` variables in your Symphony `config.php`.

### 2. Fulltext search in a data source (single section)
Adding a keyword search to an existing data source is extremely easy. Start by adding the Search Index Filter field to your section. This allows you to add a filter on this field when building a data source. For example:

* add the Search Index Filter field to your section
* modify your data source to filter this field with a filter value of `{$url-keywords}`
* attach the data source to a page and access like `/my-page/?keywords=foo+bar`

### 3. Fulltext search across multiple Sections
A full-site search can be achieved using the custom Search Index data source included with this extension. Attach this data source to a page and invoke it using the following GET parameters:

* `keywords` the string to search on e.g. `foo bar`
* `sort` (default `score`) either `id` (entry ID), `date` (entry creation date), `score` (relevance) or `score-recency` (relevance with a higher weighting for newer entries)
* `direction` (default `desc`) either `asc` or `desc`
* `per-page` (default `20`) number of results per page
* `page` the results page number
* `sections` a comma-delimited list of section handles to search within (only those with indexes will work) e.g. `articles,comments`

Your search form might look like this:

	<form action="/search/" method="get">
		<label>Search <input type="text" name="keywords" /></label>
		<input type="hidden" name="sort" value="score-recency" />
		<input type="hidden" name="per-page" value="10" />
		<input type="hidden" name="sections" value="articles,comments,categories" />
	</form>

Note that all of these variables (except for `keywords`) **have defaults** in `config.php`. So if you would rather not include these on your URLs, modify the defaults there and omit them from your HTML.

If you want to change the **name** of these variables, they can be modified in your Symphony `config.php`. If you are using Form Controls to post these variables from a form your variable names may be in the form `fields[...]`. If so, add `fields` to the `get-param-prefix` variable in your Symphony `config.php`. For more on renaming variables please see the "Configuration" section in this README for an example.

#### Using Symphony URL Parameters
The default is to use GET parameters such as `/search/?keywords=foo+bar&page=2` but if you prefer to use URL Parameters such as `/search/foo+bar/2/`, set the `get-param-prefix` variable to a value of `param_pool` in your `config.php` and the extension will look at the Param Pool rather than the $_GET array for its values.

#### Example XML

The XML returned from this data source looks like this:

	<search keywords="foo+bar+symfony" sort="score" direction="desc">
		<alternative-keywords>
			<keyword original="foo" alternative="food" distance="1" />
			<keyword original="symfony" alternative="symphony" distance="2" />
		</alternative-keywords>
		<pagination total-entries="5" total-pages="1" entries-per-page="20" current-page="1" />
		<sections>
			<section id="1" handle="articles">Articles</section>
			<section id="2" handle="comments">Comments</section>
		</sections>
		<entry id="3" section="comments">
			<excerpt>...</excerpt>
		</entry>
		<entry id="5" section="articles">
			<excerpt>...</excerpt>
		</entry>
		<entry id="2" section="articles">
			<excerpt>...</excerpt>
		</entry>
		<entry id="1" section="comments">
			<excerpt>...</excerpt>
		</entry>
		<entry id="3" section="comments">
			<excerpt>...</excerpt>
		</entry>
	</search>

This in itself is not enough to render a results page. To do so, use the `$ds-search` Output Parameter created by this data source to filter by System ID in other data sources. In the example above you would create a new data source each for Articles and Comments, filtering System ID by the `$ds-search` parameter. Use XSLT to iterate over the `<entry ... />` elements above, and cross-reference with the matching entries from the Articles and Comments data sources.

(But if you're very lazy and don't give two-hoots about performance, see the `build-entries` config option explained later.)

## Weighting
We all know that all sections are equal, only some are more equal than others ;-) You can give higher or lower weighting to results from certain sections, by issuing them a weighting when you configure their Search Index. The default is `Medium` (no weighting), but if you want more chance of entries from your section appearing higher up the search results, choose `High`; or for even more prominence `Highest`. The opposite is true: to bury entries lower down the results then choose `Low` or `Lowest`. This weighting has the effect of doubling/quadrupling or halving/quartering the original "relevance" score calculated by the search.

## Synonyms

This allows you to configure word replacements so that commonly mis-spelt terms are automatically fixed, or terms with many alternative spellings or variations can be normalised to a single spelling. An example:

* Replacement word `United Kingdom`
* Synonyms: `uk, great britain, GB, united kingdoms`

When a user searches for any of the synonym words, they will be replaced by the replacement word. So if a user searches for `countries in the UK` their search will actually use the phrase `counties in the United Kingdom`. 

Synonym matches are _not_ case-sensitive.

## Auto-complete/auto-suggest

There is a "Search Index Suggestions" data source which can be used for auto-complete search inputs. Attach this data source to a page and pass two GET parameters:

* `keywords` is the keywords to search for (the start of words are matched, less than 3 chars are ignored)
* `sort` (optional) defaults to `alphabetical` but pass `frequency` to order words by the frequency in which they occur in your index
* `sections` (optional) a comma-delimited list of section handles to return keywords for (only those with indexes will work) e.g. `articles,comments`. If omitted all indexed sections are used.

This extension does not provide the JavaScript "glue" to build the auto-suggest or auto-complete functionality. There are plenty of jQuery plugins to do this for you, and each expect slightly different XML/JSON/plain text, so I have not attempted to implement this for you. Sorry, old chum.


## Known issues
* you can not order results by relevance score when using a single data source. This is only available when using the custom Search Index data source
* if you hit the word-length limitations using boolean fulltext searching, try an alternative `mode` (`like` or `regexp`).