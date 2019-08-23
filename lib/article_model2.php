<?PHP

# Modified to allow for multiple author entries with same ordinal

class WikidataArticleEntry2 {
	public $q = '' ;
	public $title = '' ;
	public $author_names = array() ;
	public $authors = array() ;
	public $authors_stated_as = array() ;
	public $published_in = array() ;
	public $doi = '' ;
	public $pmid = '' ;
	public $topics = array() ;
	public $publication_date = '';

	public function __construct ( $item = NULL ) {
		if (! isset($item) ) {
			return ;
		}
		$this->q = $item->getQ() ;

		$title = $item->getStrings ( 'P1476' ) ;
		if ( count($title) == 0 ) $this->title = $item->getLabel() ;
		else $this->title = $title[0] ;

		$claims = $item->getClaims ( 'P2093' ) ; // Author strings
		foreach ( $claims AS $c ) {
			$author_name = $c->mainsnak->datavalue->value ;
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
				$tmp = $c->qualifiers->P1545 ;
				$num = $tmp[0]->datavalue->value ;
				if ( isset($this->author_names[$num]) ) {
					$this->author_names[$num][] = $author_name;
				} else {
					$this->author_names[$num] = [$author_name] ;
				}
			} else {
				$this->author_names[] = [$author_name] ;
			}
		}
		ksort($this->author_names) ;

		$claims = $item->getClaims ( 'P50' ) ;
		foreach ( $claims AS $c ) {
			$author_q = $item->getTarget($c) ;
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1545) ) {
				$tmp = $c->qualifiers->P1545 ;
				$num = $tmp[0]->datavalue->value ;
				if ( isset($this->authors[$num]) ) {
					$this->authors[$num][] = $author_q ;
				} else {
					$this->authors[$num] = [$author_q] ;
				}
			} else {
				$this->authors[] = [$author_q] ;
			}
			if ( isset($c->qualifiers) and isset($c->qualifiers->P1932) ) {
				$tmp = $c->qualifiers->P1932 ;
				$name = $tmp[0]->datavalue->value ;
				$this->authors_stated_as[$author_q] = $name ;
			}
		}
		ksort($this->authors) ;

		$claims = $item->getClaims ( 'P1433' ) ;
		foreach ( $claims AS $c ) {
			$this->published_in[] = $item->getTarget($c) ;
		}
		$x = $item->getStrings ( 'P356' ) ;
		if ( count($x) > 0 ) {
			$this->doi = $x[0] ;
		}
		$x = $item->getStrings ( 'P698' ) ;
		if ( count($x) > 0 ) {
			$this->pmid = $x[0] ;
		}
		if ( $item->hasClaims('P921') ) { // main subject
			$claims = $item->getClaims('P921') ;
			foreach ( $claims AS $c ) {
				$qt = $item->getTarget ( $c ) ;
				$this->topics[] = $qt ;
			}
		}
		if ( $item->hasClaims('P577') ) { // publication date
			$claims = $item->getClaims('P577') ;
			if ( count($claims) > 0 ) $this->publication_date = $claims[0]->mainsnak->datavalue->value->time ;
		}
	}

	public function formattedPublicationDate () {
		$formatted_date = '' ;
		if ( $this->publication_date != '' ) $formatted_date = DateTime::createFromFormat( '\+Y-m-d\TH:i:s\Z', $this->publication_date )->format( "Y-m-d" );
		return $formatted_date ;
	}

	public static function dateCompare ($a, $b) {
		$adate = $a->publication_date ;
		$bdate = $b->publication_date ;
		if ($adate == $bdate) {
			return 0;
		}
		return ($adate > $bdate) ? -1 : 1 ;
	}

	public function merge_candidates ($wil) {
		$all_stated_as = fetch_stated_as_for_authors($this->authors);
		$evaluation_by_index = array();
		$name_indexes = array_keys($this->author_names);
		$auth_indexes = array_keys($this->authors);
		$all_indexes = array_unique(array_merge($name_indexes, $auth_indexes));
		sort($all_indexes, SORT_NUMERIC);
		foreach ($all_indexes as $num) {
			$author_names = [] ;
			if ( isset($this->author_names[$num]) ) {
				$author_names = $this->author_names[$num] ;
			}
			$authors = [] ;
			if ( isset($this->authors[$num]) ) {
				$authors = $this->authors[$num] ;
			}
			$evaluation_by_index[$num] = evaluate_names_for_ordinal($author_names, $authors, $all_stated_as, $wil) ;
		}
		return $evaluation_by_index;
	}
}

function evaluate_names_for_ordinal($author_names, $authors, $all_stated_as, $wil) {
	$name_count = 0;
	$auth_count = 0;
	$eval = TRUE ;

	if ( isset($author_names) ) {
		$name_count = count($author_names);
	}
	if ( isset($authors) ) {
		$auth_count = count($authors);
	}
	if ($name_count + $auth_count <= 1) {
		return FALSE ;
	}
	if ($auth_count > 0) {
		$qid = $authors[0];
		foreach ($authors as $auth_qid) {
			if ($auth_qid != $qid) {
				$eval = FALSE;
				break;
			}
		}
		if ($name_count > 0) {
			$name = $wil->getItem($qid)->getLabel();
			$nm = new NameModel($name);
			$names = $nm->fuzzy_search_strings();
			if ( isset($all_stated_as[$qid]) ) {
				$names = array_merge($names, $all_stated_as[$qid]);
			}
			foreach ($author_names as $a) {
				if ( ! in_array ( $a , $names ) ) {
					$eval = FALSE;
					break;
				}
			}
		}
	} else { # No linked authors yet
		$mapping = array_combine($author_names, array_map('strlen', $author_names));
		$longest_name = array_keys($mapping, max($mapping))[0];
		$nm = new NameModel($longest_name);
		$names = $nm->fuzzy_search_strings();
		foreach ($author_names as $a) {
			if ( ! in_array ( $a , $names ) ) {
				$eval = FALSE;
				break;
			}
		}
	}
	return $eval;
}

function fetch_stated_as_for_authors($authors) {
	$names = array();
	$author_qid_map = array();
	foreach ( $authors as $author_qid_list ) {
		foreach ($author_qid_list as $qid) {
			$author_qid_map[$qid] = 1;
		}
	}
	$author_qids = array_keys($author_qid_map);
	$author_qids_for_sparql = 'wd:' . implode ( ' wd:' , $author_qids) ;
	$sparql = "SELECT DISTINCT ?author_qid ?name { VALUES ?author_qid { $author_qids_for_sparql } .
	?q p:P50 ?auth_statement .
	?auth_statement ps:P50 ?author_qid ;
                        pq:P1932 ?name .
}" ;
	$query_result = getSPARQL( $sparql ) ;
	$bindings = $query_result->results->bindings ;
	foreach ( $bindings AS $binding ) {
		$author_qid = item_id_from_uri($binding->author_qid->value) ;
		if (isset($input_names[$author_qid]) && count($input_names[$author_qid]) > 0) continue;
		$names[$author_qid][] = $binding->name->value ;
	}
	return $names;
}

function generate_article_entries2($id_list) {
	$id_uris = array_map(function($id) { return "wd:" . prepend_q($id); }, $id_list);

	$batch_size = 20 ;
	$batches = [ [] ] ;
	foreach ( $id_uris AS $k => $v ) {
		if ( count($batches[count($batches)-1]) >= $batch_size ) $batches[] = [] ;
		$batches[count($batches)-1][$k] = $v ;
	}

	$article_entries = array();
	foreach ( $batches as $batch ) {
		$new_article_entries = generate_entries_for_batch2($batch);
		$article_entries = array_merge($article_entries, $new_article_entries);
	}
	return $article_entries;
}

function generate_entries_for_batch2( $uri_list ) {
	$id_uris = implode(' ', $uri_list);
	$keyed_article_entries = array() ;

	$sparql = "SELECT ?q ?qLabel ?title ?published_in ?doi ?pmid ?topic ?pub_date WHERE {
  VALUES ?q { $id_uris } .
  OPTIONAL { ?q wdt:P1476 ?title } .
  OPTIONAL { ?q wdt:P1433 ?published_in } .
  OPTIONAL { ?q wdt:P356 ?doi } .
  OPTIONAL { ?q wdt:P698 ?pmid }.
  OPTIONAL { ?q wdt:P921 ?topic }.
  OPTIONAL { ?q wdt:P577 ?pub_date }.
  SERVICE wikibase:label { bd:serviceParam wikibase:language '[AUTO_LANGUAGE],en'. }
}" ;
	$query_result = getSPARQL( $sparql ) ;
	$bindings = $query_result->results->bindings ;
	foreach ( $bindings AS $binding ) {
		$qid = item_id_from_uri($binding->q->value) ;
		$article_entry = NULL ;
		if (isset ( $keyed_article_entries[$qid] ) ) {
			$article_entry = $keyed_article_entries[$qid] ;
		} else {
			$article_entry = new WikidataArticleEntry2();
			$article_entry->q = $qid ;
			$keyed_article_entries[$qid] = $article_entry ;
		}
		if ( isset( $binding->title ) ) {
			$article_entry->title = $binding->title->value ;
		} else if ( isset( $binding->qLabel ) ) {
			$article_entry->title = $binding->qLabel->value ;
		}
		if ( isset( $binding->published_in ) ) {
			$article_entry->published_in[] = item_id_from_uri( $binding->published_in->value );
		}
		if ( isset( $binding->doi ) ) {
			$article_entry->doi = $binding->doi->value ;
		}
		if ( isset( $binding->pmid ) ) {
			$article_entry->pmid = $binding->pmid->value ;
		}
		if ( isset( $binding->topic ) ) {
			$article_entry->topics[] = item_id_from_uri( $binding->topic->value );
		}
		if ( isset( $binding->pub_date ) ) {
			$article_entry->publication_date = '+' . $binding->pub_date->value ; // Prepend '+' to match what wikidata API returns
		}
	}

	$sparql = "SELECT ?q ?name_string ?ordinal WHERE {
  VALUES ?q { $id_uris } .
  ?q p:P2093 ?name_statement .
  ?name_statement ps:P2093 ?name_string .
  OPTIONAL { ?name_statement pq:P1545 ?ordinal } .
}" ;
	$query_result = getSPARQL( $sparql ) ;
	if (! isset($query_result->results ) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	$bindings = $query_result->results->bindings ;
	if (! is_array($bindings) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	foreach ( $bindings AS $binding ) {
		$qid = item_id_from_uri($binding->q->value) ;
		$article_entry = $keyed_article_entries[$qid] ;
		$name = $binding->name_string->value ;
		if ( isset( $binding->ordinal ) ) {
			$num = $binding->ordinal->value ;
			if ( isset($article_entry->author_names[$num]) ) {
				$article_entry->author_names[$num][] = $name ;
			} else {
				$article_entry->author_names[$num] = [$name] ;
			}
		} else {
			$article_entry->author_names[] = [$name] ;
		}
	}

	$sparql = "SELECT ?q ?author ?stated_as ?ordinal WHERE {
  VALUES ?q { $id_uris } .
  ?q p:P50 ?author_statement .
  ?author_statement ps:P50 ?author .
  OPTIONAL { ?author_statement pq:P1932 ?stated_as } .
  OPTIONAL { ?author_statement pq:P1545 ?ordinal } .
}" ;
	$query_result = getSPARQL( $sparql ) ;
	if (! isset($query_result->results ) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	$bindings = $query_result->results->bindings ;
	if (! is_array($bindings) ) {
		print "WARNING: no results from SPARQL query '$sparql'";
		return $keyed_article_entries;
	}
	foreach ( $bindings AS $binding ) {
		$qid = item_id_from_uri($binding->q->value) ;
		$article_entry = $keyed_article_entries[$qid] ;
		$author_q = item_id_from_uri($binding->author->value) ;
		if ( isset( $binding->ordinal ) ) {
			$num = $binding->ordinal->value ;
			if ( isset ( $article_entry->authors[$num] ) ) {
				$article_entry->authors[$num][] = $author_q ;
			} else {
				$article_entry->authors[$num] = [$author_q] ;
			}
		} else {
			$article_entry->authors[] = [$author_q] ;
		}
		if ( isset( $binding->stated_as ) ) {
			$article_entry->authors_stated_as[$author_q] = $binding->stated_as->value ;
		}
	}

	foreach( $keyed_article_entries AS $article_entry ) {
		ksort($article_entry->author_names) ;
		ksort($article_entry->authors) ;
		if (count($article_entry->published_in) > 1) {
			$article_entry->published_in = array_unique($article_entry->published_in);
		}
		if (count($article_entry->topics) > 1) {
			$article_entry->topics = array_unique($article_entry->topics);
		}
	}

	return $keyed_article_entries;
}

?>