<?php
/* Copyright 2015, Enrico Ros

Licensed under the Apache License, Version 2.0 (the "License");
you may not use this file except in compliance with the License.
You may obtain a copy of the License at

    http://www.apache.org/licenses/LICENSE-2.0

Unless required by applicable law or agreed to in writing, software
distributed under the License is distributed on an "AS IS" BASIS,
WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
See the License for the specific language governing permissions and
limitations under the License. */

require_once 'jobs_interface.php';

// quickly bail out if there is no job for me
$workingQuery = work_getOneForMe();
if ($workingQuery == null)
    die("no jobs for me\n");

// configuration
const VIDEOS_PER_QUERY = 100;
const MIN_VIDEO_VIEWS = 2000;
const FORCE_REINDEX = false;
define('VIDEOS_PROCESSED_SET', 'jam_videos_processed');
define('VIDEOS_INDEXED_SET_NAME', 'jam_videos_indexed');

// create the global objects
require_once 'YTMachine.php';
$ytMachine = new \YTMachine();

require_once 'IndexMachine_Algolia.php';
$indexMachine = new \IndexMachine_Algolia(isset($_GET['index']) ? 'yt_' . $_GET['index'] : '');


// loop until there's work
do {
    // let's go with the current query
    $outPrefix = 'Q: ' . $workingQuery . ': ';
    echo $outPrefix . "started\n";
    $someQueries = [ $workingQuery ];

    // search youtube for N queries, for M (4) ordering criteria
    /* @var $videoLeads YTVideo[] */
    $videoLeads = [];
    $orders = ['relevance', 'viewCount', 'rating', 'date'];
    foreach ($someQueries as $query) {
        foreach ($orders as $order) {

            // search for the current Query, using each of the 4 criteria...
            $criteria = new YTSearchCriteria(trim($query));
            $criteria->setOrder($order);
            $videos = $ytMachine->searchVideos($criteria, VIDEOS_PER_QUERY);
            // ...and merge into 1 list
            $ytMachine->mergeUniqueVideos($videoLeads, $videos);

        }
    }
    echo $outPrefix . sizeof($videoLeads) . " found\n";
    echo $outPrefix . 'processing [';

    // process each video: get caption, get details, send to index
    /* @var $videoLeads YTVideo[] */
    $newVideos = [];
    $n = 1;
    shuffle($videoLeads);
    foreach ($videoLeads as $video) {
        // OPTIMIZATION: skip resolving if we already did it in the past and
        // the video has already been indexed (or we know it can't be).
        if (!FORCE_REINDEX && CacheMachine::setContains(VIDEOS_PROCESSED_SET, $video->videoId)) {
            echo ' ';
            continue;
        }
        CacheMachine::addToSet(VIDEOS_PROCESSED_SET, $video->videoId);

        // resolve the captions, and skip if failed
        if (!$video->resolveCaptions()) {
            echo 'C';
            continue;
        }

        // also resolve details: numbers of views, etc.
        $video->resolveDetails();
        if ($video->countViews < MIN_VIDEO_VIEWS) {
            echo 'V';
            continue;
        }

        // send it to the Index (to be indexed)
        if (!$indexMachine->addOrUpdate($video->videoId, $video)) {
            echo 'S';
            continue;
        }

        // video processed, all details are present, subtitles downloaded and indexed
        array_push($newVideos, $video);
        CacheMachine::addToSet(VIDEOS_INDEXED_SET_NAME, $video->videoId);
        echo '.';
    }
    echo "]\n";
    echo $outPrefix . sizeof($newVideos) . " indexed\n";

    // done
    work_doneWithMyCurrent();

} while ($workingQuery = work_getOneForMe());

// all done for me
echo "\n";
