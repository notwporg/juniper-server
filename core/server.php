<?php

namespace Juniper\Server;

require_once( JUNIPER_SERVER_DIR . '/core/config.php' );
require_once( JUNIPER_SERVER_DIR . '/core/db.php' );
require_once( JUNIPER_SERVER_DIR . '/core/log-listener.php' );
require_once( JUNIPER_SERVER_DIR . '/core/log-listener-shell.php' );
require_once( JUNIPER_SERVER_DIR . '/core/log.php' );

class Server {
    public $config = null;
    var $db = null;

    public function __construct() {
        Log::instance()->installListener( new LogListenerShell() );
    }

    public function curlGet( $url ) {
        $ch = curl_init();
        curl_setopt( $ch, CURLOPT_URL, $url );
        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
        curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
        curl_setopt( $ch, CURLOPT_TIMEOUT, 10000 );

        curl_setopt( $ch, CURLOPT_USERAGENT, 'Juniper/Server' );
        $response = curl_exec( $ch );
    
        return $response;
    }

    public function loadConfig() {
        $this->config = Config::load( JUNIPER_SERVER_DIR . '/site.yaml' );
        if ( empty( $this->config[ 'repo' ][ 'sites' ] ) ) {
            $this->config[ 'repo' ][ 'sites' ] = [];
        }

        $validSitesFile = JUNIPER_SERVER_DIR . '/config/sites-valid.yaml';
        if ( file_exists( $validSitesFile ) ) {
            $validSites = Config::load( $validSitesFile );

            $this->config[ 'repo' ][ 'sites' ] = array_merge( $this->config[ 'repo' ][ 'sites' ], $validSites[ 'sites' ] );
        }

        $this->config = Config::flatten( $this->config );
    }

    public function destroyAll() {
        $this->db->destroyAll();
    }

    public function getConfigSetting( $setting ) {
        if ( !empty( $this->config[ $setting ] ) ) {
            return $this->config[ $setting ];
        } else {
            return false;
        }
    }

    public function startDb() {
        $this->db = new DB();
        
        LOG( "Opening Sqlite database", 1 );

        $this->db->init();
    }

    public function addSiteToDb( $siteUrl ) {
        LOG( sprintf( "Adding site to DB [%s]", $siteUrl ), 2 );
        $queryString = sprintf( 
            "INSERT INTO sites (url,num_failures,updated_at,first_added) VALUES (%s,0,%u,%u)",
            $this->db->escapeWithTicks( $siteUrl ),
            time(),
            time()
        );

        $this->db->query( $queryString );

        return $this->db->getLastInsertId();
    }

    public function getTotalDownloads() {
        $total = 0;

        $queryString = "SELECT SUM(total_downloads) as total FROM addons";
        $result = $this->db->query( $queryString );
        if ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $total = $row[ 'total' ];
        }

        return $total;
    }

    public function getTotalPlugins() {
        $total = 0;

        $queryString = "SELECT count(0) as total FROM addons";
        $result = $this->db->query( $queryString );
        if ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $total = $row[ 'total' ];
        }

        return $total;
    }

    public function addAddonToDb( $siteId, $addOnData ) {
        LOG( sprintf( "Adding add-on to DB [%s]", $addOnData->repository->fullName ), 2 );

        if ( empty( $addOnData->totalReleaseDownloads ) ) {
            $addOnData->totalReleaseDownloads = 0;
        }

        $queryString = sprintf( 
            "INSERT INTO addons (site_id,type,name,slug,branch,src,author_name,signing_authority,author_url,avatar_url,description,readme,stable_version,repo_version,banner_image_url,requires_php,requires_at_least,tested_up_to,open_issues_count,stars_count,total_downloads,updated_at,created_at) " . 
            "VALUES (%u,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%u,%u,%u,%u,%u)",
            $siteId,
            $this->db->escapeWithTicks( 'plugin' ),
            $this->db->escapeWithTicks( $addOnData->info->pluginName ),
            $this->db->escapeWithTicks( $addOnData->repository->fullName ),
            !empty( $addOnData->repository->primaryBranch ) ? $this->db->escapeWithTicks( $addOnData->repository->primaryBranch )  : $this->db->escapeWithTicks( '' ),
            $this->db->escapeWithTicks( 'github' ),
            $this->db->escapeWithTicks( $addOnData->info->author ),
            $this->db->escapeWithTicks( $addOnData->info->signingAuthority ),
            $this->db->escapeWithTicks( $addOnData->repository->owner->ownerUrl ),
            $this->db->escapeWithTicks( $addOnData->repository->owner->avatarUrl ),
            $this->db->escapeWithTicks( $addOnData->info->description ),
            $this->db->escapeWithTicks( $addOnData->info->readmeHtml ),
            $this->db->escapeWithTicks( $addOnData->info->stableVersion ),
            $this->db->escapeWithTicks( $addOnData->info->version ),
            $this->db->escapeWithTicks( $addOnData->info->bannerImage ),
            $this->db->escapeWithTicks( $addOnData->info->requiresPHP ),
            $this->db->escapeWithTicks( $addOnData->info->requiresAtLeast ),
            $this->db->escapeWithTicks( $addOnData->info->testedUpTo ),
            $addOnData->repository->openIssuesCount,  // open issues
            $addOnData->repository->starsCount, 
            $addOnData->totalReleaseDownloads,
            time(),
            time()
        );

        $this->db->query( $queryString );
        return $this->db->getLastInsertId();
    }

    public function addReleaseToDb( $addOnId, $releaseData ) {
        $queryString = sprintf( 
            "INSERT INTO releases (addon_id,release_tag,url,name,description,download_url,signed,release_date) " . 
            "VALUES (%u,%s,%s,%s,%s,%s,%d,%u)",
            $addOnId,
            $this->db->escapeWithTicks( $releaseData->tag ),
            $this->db->escapeWithTicks( $releaseData->url ),
            $this->db->escapeWithTicks( $releaseData->name ),
            $this->db->escapeWithTicks( $releaseData->body ),
            $this->db->escapeWithTicks( $releaseData->downloadUrl ),
            $releaseData->signed ? 1 : 0,
            $releaseData->publishedAt
        );

        $this->db->query( $queryString );
    }

     public function addIssueToDb( $addOnId, $issueData ) {
        $queryString = sprintf( 
            "INSERT INTO issues (addon_id,url,title,body,user,user_avatar_url,user_url,updated_at_date) " . 
            "VALUES (%u,%s,%s,%s,%s,%s,%s,%u)",
            $addOnId,
            $this->db->escapeWithTicks( $issueData->url ),
            $this->db->escapeWithTicks( $issueData->title ),
            $this->db->escapeWithTicks( $issueData->body ),
            $this->db->escapeWithTicks( $issueData->postedBy->user ),
            $this->db->escapeWithTicks( $issueData->postedBy->avatarUrl ),
            $this->db->escapeWithTicks( $issueData->postedBy->userUrl ),
            $issueData->updatedAt
        );

        $this->db->query( $queryString );
    }

    public function getPluginList() {
        $queryString = sprintf( "SELECT * FROM addons WHERE type=%s ORDER BY name", $this->db->escapeWithTicks( 'plugin' ) );
        $result = $this->db->query( $queryString );

        $plugins = [];
        while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $plugins[] = $row;
        }

        return $plugins;
    }

    public function getRankedPluginList() {
        $queryString = sprintf( "SELECT * FROM addons WHERE type=%s ORDER BY stars_count DESC", $this->db->escapeWithTicks( 'plugin' ) );
        $result = $this->db->query( $queryString );

        $plugins = [];
        while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $plugins[] = $row;
        }

        return $plugins;
    }

    public function getPluginReleases( $id ) {
        $queryString = sprintf( "SELECT * FROM releases WHERE addon_id=%d ORDER BY release_date DESC", $id );
        $result = $this->db->query( $queryString );

        $releases = [];
        while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $releases[] = $row;
        }

        return $releases;
    }
       
    public function getNewestAddons() {
        $queryString = sprintf( "SELECT * FROM addons ORDER BY created_at DESC LIMIT 2" );
        $result = $this->db->query( $queryString );

        $addons = [];
        while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $addons[] = $row;
        }

        return $addons;
    }

    public function getPluginIssues( $id ) {
        $queryString = sprintf( "SELECT * FROM issues WHERE addon_id=%d ORDER BY updated_at_date DESC", $id );
        $result = $this->db->query( $queryString );

        $issues = [];
        while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $issues[] = $row;
        }

        return $issues;
    }

    public function stopDb() {
        LOG( "Closing Sqlite database", 1 );
        $this->db->shutdown();
    }

    public function getSites() {
       return $this->config[ 'repo.sites' ];
    }
}