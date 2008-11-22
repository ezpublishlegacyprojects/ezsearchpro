<?php
//
// Definition of eZSearchEnginePro class
//
// Created on: <20-Jun-2007 00:00:00 ar>
//
// ## BEGIN COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
// SOFTWARE NAME: eZ Publish
// SOFTWARE RELEASE: 4.x
// COPYRIGHT NOTICE: Copyright (C) 1999-2008 eZ Systems AS
// SOFTWARE LICENSE: GNU General Public License v2.0
// NOTICE: >
//   This program is free software; you can redistribute it and/or
//   modify it under the terms of version 2.0  of the GNU General
//   Public License as published by the Free Software Foundation.
//
//   This program is distributed in the hope that it will be useful,
//   but WITHOUT ANY WARRANTY; without even the implied warranty of
//   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//   GNU General Public License for more details.
//
//   You should have received a copy of version 2.0 of the GNU General
//   Public License along with this program; if not, write to the Free
//   Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
//   MA 02110-1301, USA.
//
//
// ## END COPYRIGHT, LICENSE AND WARRANTY NOTICE ##
//

/*!
  \class eZSearchEnginePro ezsearch.php

*/

class eZSearchEnginePro extends eZSearchEngine
{
    /*!
     */
    function eZSearchEnginePro()
    {
        $generalFilter = array( 'subTreeTable' => '',
                                'searchDateQuery' => '',
                                'sectionQuery' => '',
                                'classQuery' => '',
                                'classAttributeQuery' => '',
                                'searchPartText' => '',
                                'subTreeSQL' => '',
                                'sqlPermissionChecking' => array( 'from' => '',
                                                                  'where' => '' ) );
        $this->GeneralFilter = $generalFilter;
    }

    /*!
     Adds an object to the search database.
    */
    function addObject( $contentObject, $uri )
    {
        $searchIni = eZINI::instance( 'search.ini' );
        $attributeFactors = $searchIni->variable( 'Indexing', 'AttributeFactors' );
        $namePatternFactor = (float) $searchIni->variable( 'Indexing', 'NamePatternFactor' );
        $datatypeFactors = $searchIni->variable( 'Indexing', 'DatatypeFactors' );
        $wordCountFactor = (float) $searchIni->variable( 'Indexing', 'WordCountFactor' );
        
        if ( !is_array( $attributeFactors ) )
            $attributeFactors = array();
            
        if ( !is_array( $datatypeFactors ) )
            $datatypeFactors = array();
        
        $contentObjectID = $contentObject->attribute( 'id' );
        $currentVersion = $contentObject->currentVersion();

        if ( !$currentVersion )
        {
            $errCurrentVersion = $contentObject->attribute( 'current_version');
            require_once( "lib/ezutils/classes/ezdebug.php" );
            eZDebug::writeError( "Failed to fetch \"current version\" ({$errCurrentVersion})" .
                                 " of content object (ID: {$contentObjectID})", 'eZSearchEnginePro' );
            return;
        }
        
        $contentClass = $contentObject->contentClass();
        $classNamePattern = $contentClass->ContentObjectName;
        $classNamePattern = str_replace(array('|', '<', '>', ',', '.', '-', '!', '+', '(', ')', '&', '%'), ' ', $classNamePattern);
        $classNamePattern = $this->splitString( $classNamePattern );        

        $indexArray = array();
        $indexArrayOnlyWords = array();

        $wordCount = 0;
        $placement = 0;
        $previousWord = '';

        eZContentObject::recursionProtectionStart();
        foreach ( $currentVersion->contentObjectAttributes() as $attribute )
        {
            $metaData = array();
            $classAttribute = $attribute->contentClassAttribute();
            if ( $classAttribute->attribute( "is_searchable" ) == 1 )
            {
                // Fetch attribute translations
                $attributeTranslations = $attribute->fetchAttributeTranslations();
                
                //calculate attributeFactor for search ranking
                $attributeFactor = 1;
                $attributeID = $attribute->attribute( 'contentclassattribute_id' );
                $attributeIdentifier = $attribute->attribute( 'contentclass_attribute_identifier' );
                $datatypeIdentifier = $attribute->attribute( 'data_type_string' );
                if ( isset( $attributeFactors[ $attributeID ] ) )
                {
                    $attributeFactor = $attributeFactors[ $attributeID ];
                }
                elseif ( isset( $attributeFactors[ $attributeIdentifier ] ) )
                {
                    $attributeFactor = $attributeFactors[ $attributeIdentifier ];
                }
                elseif ( $namePatternFactor && in_array( $attributeIdentifier, $classNamePattern ) )
                {
                    $attributeFactor = $namePatternFactor;
                }
                elseif ( isset( $datatypeFactors[ $datatypeIdentifier ] ) )
                {
                    $attributeFactor = $datatypeFactors[ $datatypeIdentifier ];
                }

                foreach ( $attributeTranslations as $translation )
                {
                    $tmpMetaData = $translation->metaData();
                    if( ! is_array( $tmpMetaData ) )
                    {
                        $tmpMetaData = array( array( 'id' => $attributeIdentifier,
                                                     'text' => $tmpMetaData ) );
                    }
                    $metaData = array_merge( $metaData, $tmpMetaData );
                }

                foreach( $metaData as $metaDataPart )
                {
                    $text = eZSearchEnginePro::normalizeText( strip_tags(  $metaDataPart['text'] ), true );

                    // Split text on whitespace
                    if ( is_numeric( trim( $text ) ) )
                    {
                        $integerValue = (int) $text;
                    }
                    else
                    {
                        $integerValue = 0;
                    }
                                      
                    $wordFrequencyCount = 0;
                    $wordArray = split( " ", $text );
                    $indexArrayStart = count( $indexArray );

                    foreach ( $wordArray as $word )
                    {
                        if ( trim( $word ) != '' )
                        {
                            // words stored in search index are limited to 150 characters
                            if ( strlen( $word ) > 150 )
                            {
                                $word = substr( $word, 0, 150 );
                            }
                            $indexArray[] = array( 'Word' => $word,
                                                   'ContentClassAttributeID' => $attributeID,
                                                   'identifier' => $metaDataPart['id'],
                                                   'frequency' => 0.0,
                                                   'is_url' => false,
                                                   'integer_value' => $integerValue );
                            $indexArrayOnlyWords[] = $word;
                            $wordFrequencyCount++;
                            $wordCount++;
                            //if we have "www." before word than
                            //treat it as url and add additional entry to the index
                            if ( substr( strtolower($word), 0, 4 ) == 'www.' )
                            {
                                $additionalUrlWord = substr( $word, 4 );
                                $indexArray[] = array( 'Word' => $additionalUrlWord,
                                                       'ContentClassAttributeID' => $attributeID,
                                                       'identifier' => $metaDataPart['id'],
                                                       'frequency' => 0.0,
                                                       'is_url' => true,
                                                       'integer_value' => $integerValue );
                                $indexArrayOnlyWords[] = $additionalUrlWord;
                                $wordFrequencyCount++;
                                $wordCount++;
                            }
                        }
                    }
                    // calculate the frequency
                    if ( $attributeFactor )
                    {
                        if ( $wordCountFactor )
                            $wordFrequencyCount = $wordFrequencyCount * $wordCountFactor;
                        else
                            $wordFrequencyCount = 1;
                        
                        for ( $key = $indexArrayStart; $key < count( $indexArray ); $key ++ )
                        {
                            $indexArray[$key]['frequency'] = $attributeFactor / $wordFrequencyCount;
                        }
                    }
                }
            }
        }
        eZContentObject::recursionProtectionEnd();

        $indexArrayOnlyWords = array_unique( $indexArrayOnlyWords );

        $wordIDArray = $this->buildWordIDArray( $indexArrayOnlyWords );

        $db = eZDB::instance();
        $db->begin();
        for( $arrayCount = 0; $arrayCount < $wordCount; $arrayCount += 1000 )
        {
            $placement = $this->indexWords( $contentObject, array_slice( $indexArray, $arrayCount, 1000 ), $wordIDArray, $placement );
        }
        $db->commit();
    }

    /*!
      \private

      \param contentObject
      \param indexArray
      \param wordIDArray
      \param placement

      \return last placement
      Index wordIndex
    */
    function indexWords( $contentObject, $indexArray, $wordIDArray, $placement = 0 )
    {
        $db = eZDB::instance();

        $contentObjectID = $contentObject->attribute( 'id' );

        // Count the total words in index text
        $totalWordCount = count( $indexArray );

        // Initialize transformation system
        $trans = eZCharTransform::instance();

        $prevWordID = 0;
        $nextWordID = 0;
        $classID = $contentObject->attribute( 'contentclass_id' );
        $sectionID = $contentObject->attribute( 'section_id' );
        $published = $contentObject->attribute( 'published' );
        $valuesStringList = array();
        for ( $i = 0; $i < count( $indexArray ); $i++ )
        {
            $indexWord = $indexArray[$i]['Word'];
            $contentClassAttributeID = $indexArray[$i]['ContentClassAttributeID'];
            $identifier = $indexArray[$i]['identifier'];
            $integerValue = $indexArray[$i]['integer_value'];
            $wordID = $wordIDArray[$indexWord];

            if ( isset( $indexArray[$i+1] ) )
            {
                $nextIndexWord = $indexArray[$i+1]['Word'];
                $nextWordID = $wordIDArray[$nextIndexWord];
            }
            else
                $nextWordID = 0;

            $frequency = $indexArray[$i]['frequency'];
            $valuesStringList[] = " ( '$wordID', '$contentObjectID', '$frequency', '$placement', '$nextWordID', '$prevWordID', '$classID', '$contentClassAttributeID', '$published', '$sectionID', '$identifier', '$integerValue' ) ";

            $prevWordID = $wordID;
            $placement++;
        }
        $dbName = $db->databaseName();

        if ( $dbName == 'mysql' )
        {
            if ( count( $valuesStringList ) > 0 )
            {
                $valuesString = implode( ',', $valuesStringList );
                $db->query( "INSERT INTO
                           ezsearch_object_word_link
                        ( word_id,
                          contentobject_id,
                          frequency,
                          placement,
                          next_word_id,
                          prev_word_id,
                          contentclass_id,
                          contentclass_attribute_id,
                          published,
                          section_id,
                          identifier,
                          integer_value )
                          VALUES $valuesString" );
            }
        }
        else
        {
            $db->begin();
            foreach ( array_keys( $valuesStringList ) as $key )
            {
                $valuesString = $valuesStringList[$key];
                $db->query("INSERT INTO
                           ezsearch_object_word_link
                           ( word_id,
                             contentobject_id,
                             frequency,
                             placement,
                             next_word_id,
                             prev_word_id,
                             contentclass_id,
                             contentclass_attribute_id,
                             published,
                             section_id,
                             identifier,
                             integer_value )
                             VALUES $valuesString"  );
            }
            $db->commit();
        }

        return $placement;
    }

    /*!
     Runs a query to the search engine.
    */
    function search( $searchText, $params = array(), $searchTypes = array() )
    {
        if ( count( $searchTypes ) == 0 )
        {
            $searchTypes['general'] = array();
            $searchTypes['subtype'] = array();
            $searchTypes['and'] = array();
        }
        else if ( !isset( $searchTypes['general'] ) )
        {
            $searchTypes['general'] = array();
        }

        $allowSearch = true;
        if ( trim( $searchText ) === '' )
        {
            $ini = eZINI::instance();
            if ( $ini->variable( 'SearchSettings', 'AllowEmptySearch' ) != 'enabled' )
                $allowSearch = false;
            if ( isset( $params['AllowEmptySearch'] ) )
                $allowSearch = $params['AllowEmptySearch'];
        }

        if ( !$allowSearch )
        {
            return array( 'SearchResult' => array(),
                  'SearchCount' => 0,
                  'StopWordArray' => array() );
        }

        $searchText = $this->normalizeText( $searchText, false );
        $db = eZDB::instance();

        $nonExistingWordArray = array();
        $searchTypeMap = array( 'class' => 'SearchContentClassID',
                                'publishdate' => 'SearchDate',
                                'subtree' => 'SearchSubTreeArray' );

        foreach ( $searchTypes['general'] as $searchType )
        {
            $params[$searchTypeMap[$searchType['subtype']]] = $searchType['value'];
        }

        if ( isset( $params['SearchOffset'] ) )
            $searchOffset = $params['SearchOffset'];
        else
            $searchOffset = 0;

        if ( isset( $params['SearchLimit'] ) )
            $searchLimit = $params['SearchLimit'];
        else
            $searchLimit = 10;

        if ( isset( $params['SearchContentClassID'] ) )
            $searchContentClassID = $params['SearchContentClassID'];
        else
            $searchContentClassID = -1;

        if ( isset( $params['SearchSectionID'] ) )
            $searchSectionID = $params['SearchSectionID'];
        else
            $searchSectionID = -1;

        if ( isset( $params['SearchDate'] ) )
            $searchDate = $params['SearchDate'];
        else
            $searchDate = -1;

        if ( isset( $params['SearchTimestamp'] ) )
            $searchTimestamp = $params['SearchTimestamp'];
        else
            $searchTimestamp = false;

        if ( isset( $params['SearchContentClassAttributeID'] ) )
            $searchContentClassAttributeID = $params['SearchContentClassAttributeID'];
        else
            $searchContentClassAttributeID = -1;

        if ( isset( $params['SearchSubTreeArray'] ) )
            $subTreeArray = $params['SearchSubTreeArray'];
        else
            $subTreeArray = array();

        if ( isset( $params['SortArray'] ) )
            $sortArray = $params['SortArray'];
        else
            $sortArray = array();

        $ignoreVisibility = isset( $params['IgnoreVisibility'] ) ? $params['IgnoreVisibility'] : false;

        // strip multiple spaces
        $searchText = preg_replace( "(\s+)", ' ', $searchText );

        // find the phrases
        $phrasesResult = $this->getPhrases( $searchText );
        $phraseTextArray = $phrasesResult['phrases'];
        $nonPhraseText = $phrasesResult['nonPhraseText'];
        $fullText = $phrasesResult['fullText'];

        $sectionQuery = '';
        if ( is_numeric( $searchSectionID ) and  $searchSectionID > 0 )
        {
            $sectionQuery = "ezsearch_object_word_link.section_id = '$searchSectionID' AND ";
        }
        else if ( is_array( $searchSectionID ) )
        {
            // Build query for searching in an array of sections
            $sectionString = implode( ', ', $searchSectionID );
            $sectionQuery = "ezsearch_object_word_link.section_id IN ( $sectionString ) AND ";
        }

        $searchDateQuery = '';
        if ( ( is_numeric( $searchDate ) and  $searchDate > 0 ) or
             $searchTimestamp )
        {
            $date = new eZDateTime();
            $timestamp = $date->timeStamp();
            $day = $date->attribute('day');
            $month = $date->attribute('month');
            $year = $date->attribute('year');
            $publishedDateStop = false;
            if ( $searchTimestamp )
            {
                if ( is_array( $searchTimestamp ) )
                {
                    $publishedDate = $searchTimestamp[0];
                    $publishedDateStop = $searchTimestamp[1];
                }
                else
                    $publishedDate = $searchTimestamp;
            }
            else
            {
                switch ( $searchDate )
                {
                    case 1:
                    {
                        $adjustment = 24*60*60; //seconds for one day
                        $publishedDate = $timestamp - $adjustment;
                    } break;
                    case 2:
                    {
                        $adjustment = 7*24*60*60; //seconds for one week
                        $publishedDate = $timestamp - $adjustment;
                    } break;
                    case 3:
                    {
                        $adjustment = 31*24*60*60; //seconds for one month
                        $publishedDate = $timestamp - $adjustment;
                    } break;
                    case 4:
                    {
                        $adjustment = 3*31*24*60*60; //seconds for three months
                        $publishedDate = $timestamp - $adjustment;
                    } break;
                    case 5:
                    {
                        $adjustment = 365*24*60*60; //seconds for one year
                        $publishedDate = $timestamp - $adjustment;
                    } break;
                    default:
                    {
                        $publishedDate = $date->timeStamp();
                    }
                }
            }
            $searchDateQuery = "ezsearch_object_word_link.published >= '$publishedDate' AND ";
            if ( $publishedDateStop )
                $searchDateQuery .= "ezsearch_object_word_link.published <= '$publishedDateStop' AND ";
            $this->GeneralFilter['searchDateQuery'] = $searchDateQuery;
        }

        $classQuery = "";
        if ( is_numeric( $searchContentClassID ) and $searchContentClassID > 0 )
        {
            // Build query for searching in one class
            $classQuery = "ezsearch_object_word_link.contentclass_id = '$searchContentClassID' AND ";
            $this->GeneralFilter['classAttributeQuery'] = $classQuery;
        }
        else if ( is_array( $searchContentClassID ) )
        {
            // Build query for searching in a number of classes
            $classString = $db->implodeWithTypeCast( ', ', $searchContentClassID, 'int' );
            $classQuery = "ezsearch_object_word_link.contentclass_id IN ( $classString ) AND ";
            $this->GeneralFilter['classAttributeQuery'] = $classQuery;
        }

        $classAttributeQuery = "";
        if ( is_numeric( $searchContentClassAttributeID ) and  $searchContentClassAttributeID > 0 )
        {
            $classAttributeQuery = "ezsearch_object_word_link.contentclass_attribute_id = '$searchContentClassAttributeID' AND ";
        }
        else if ( is_array( $searchContentClassAttributeID ) )
        {
            // Build query for searching in a number of attributes
            $attributeString = implode( ', ', $searchContentClassAttributeID );
            $classAttributeQuery = "ezsearch_object_word_link.contentclass_attribute_id IN ( $attributeString ) AND ";
        }

        // Get the total number of objects
        $totalObjectCount = $this->fetchTotalObjectCount();

        $searchPartsArray = array();
        $wordIDHash = array();
        $wildCardCount = 0;
        if ( trim( $searchText ) != '' )
        {
            $wordIDArrays = $this->prepareWordIDArrays( $searchText );
            $wordIDArray = $wordIDArrays['wordIDArray'];
            $wordIDHash = $wordIDArrays['wordIDHash'];
            $wildIDArray = $wordIDArrays['wildIDArray'];
            $wildCardCount = $wordIDArrays['wildCardCount'];
            $searchPartsArray = $this->buildSearchPartArray( $phraseTextArray, $nonPhraseText, $wordIDHash, $wildIDArray );
        }

        /* OR search, not used in this version
        $doOrSearch = false;

        if ( $doOrSearch == true )
        {
            // build fulltext search SQL part
            $searchWordArray = $this->splitString( $fullText );
            $fullTextSQL = "";
            if ( count( $searchWordArray ) > 0 )
            {
                $i = 0;
                // Build the word query string
                foreach ( $searchWordArray as $searchWord )
                {
                    $wordID = null;
                    if ( isset( $wordIDHash[$searchWord] ) )
                        $wordID = $wordIDHash[$searchWord]['id'];

                    if ( is_numeric( $wordID ) and ( $wordID > 0 ) )
                    {
                        if ( $i == 0 )
                            $fullTextSQL .= "ezsearch_object_word_link.word_id='$wordID' ";
                        else
                            $fullTextSQL .= " OR ezsearch_object_word_link.word_id='$wordID' ";
                    }
                    else
                    {
                        $nonExistingWordArray[] = $searchWord;
                    }
                    $i++;
                }
                $fullTextSQL = " ( $fullTextSQL ) AND ";
            }
        }*/

        // Search only in specific sub trees
        $subTreeSQL = '';
        $subTreeTable = '';
        if ( count( $subTreeArray ) > 0 )
        {
            // Fetch path_string value to use when searching subtrees
            $i = 0;
            $doSubTreeSearch = false;
            $subTreeNodeSQL = '';
            foreach ( $subTreeArray as $nodeID )
            {
                if ( is_numeric( $nodeID ) and ( $nodeID > 0 ) )
                {
                    $subTreeNodeSQL .= ' ' . $nodeID;

                    if ( isset( $subTreeArray[$i + 1] ) and
                         is_numeric( $subTreeArray[$i + 1] ) )
                        $subTreeNodeSQL .= ', ';

                    $doSubTreeSearch = true;
                }
                $i++;
            }

            if ( $doSubTreeSearch == true )
            {

                $subTreeNodeSQL = '( ' . $subTreeNodeSQL;
                //$subTreeTable = ", ezcontentobject_tree ";
                $subTreeTable = '';
                $subTreeNodeSQL .= ' ) ';
                $nodeQuery = 'SELECT node_id, path_string FROM ezcontentobject_tree WHERE node_id IN ' . $subTreeNodeSQL;

                // Build SQL subtre search query
                $subTreeSQL = " ( ";

                $nodeArray = $db->arrayQuery( $nodeQuery );
                $i = 0;
                foreach ( $nodeArray as $node )
                {
                    $pathString = $node['path_string'];

                    $subTreeSQL .= " ezcontentobject_tree.path_string like '$pathString%' ";

                    if ( $i < ( count( $nodeArray ) -1 ) )
                        $subTreeSQL .= ' OR ';
                    $i++;
                }
                $subTreeSQL .= ' ) AND ';
                $this->GeneralFilter['subTreeTable'] = $subTreeTable;
                $this->GeneralFilter['subTreeSQL'] = $subTreeSQL;

            }
        }

        $limitation = false;
        if ( isset( $params['Limitation'] ) )
        {
            $limitation = $params['Limitation'];
        }

        $limitationList = eZContentObjectTreeNode::getLimitationList( $limitation );
        $sqlPermissionChecking = eZContentObjectTreeNode::createPermissionCheckingSQL( $limitationList );
        $this->GeneralFilter['sqlPermissionChecking'] = $sqlPermissionChecking;

        $useVersionName = true;
        if ( $useVersionName )
        {
            $versionNameTables = ', ezcontentobject_name ';
            $versionNameTargets = ', ezcontentobject_name.name as name,  ezcontentobject_name.real_translation ';

            $versionNameJoins = " and  ezcontentobject_tree.contentobject_id = ezcontentobject_name.contentobject_id and
                              ezcontentobject_tree.contentobject_version = ezcontentobject_name.content_version and ";
            $versionNameJoins .= eZContentLanguage::sqlFilter( 'ezcontentobject_name', 'ezcontentobject' );
        }

        /// Only support AND search at this time
        // build fulltext search SQL part
        $searchWordArray = $this->splitString( $fullText );
        $searchWordCount = count( $searchWordArray );
        $fullTextSQL = '';
        $stopWordArray = array( );
        $ini = eZINI::instance();

        $tmpTableCount = 0;
        $i = 0;
        foreach ( $searchTypes['and'] as $searchType )
        {
            $methodName = $this->constructMethodName( $searchType );
            $intermediateResult = $this->callMethod( $methodName, array( $searchType ) );
            if ( $intermediateResult == false )
            {
                // cleanup temp tables
                $db->dropTempTableList( $sqlPermissionChecking['temp_tables'] );

                return array( 'SearchResult' => array(),
                              'SearchCount' => 0,
                              'StopWordArray' => array() );
            }
        }

        // Do not execute search if site.ini:[SearchSettings]->AllowEmptySearch is enabled, but no conditions are set.
        if ( !$searchDateQuery &&
             !$sectionQuery &&
             !$classQuery &&
             !$classAttributeQuery &&
             !$searchPartsArray &&
             !$subTreeSQL )
        {
            // cleanup temp tables
            $db->dropTempTableList( $sqlPermissionChecking['temp_tables'] );

            return array( 'SearchResult' => array(),
                          'SearchCount' => 0,
                          'StopWordArray' => array() );
        }

        $i = $this->TempTablesCount;

        // Loop every word and insert result in temporary table

        // Determine whether we should search invisible nodes.
        $showInvisibleNodesCond = eZContentObjectTreeNode::createShowInvisibleSQLString( !$ignoreVisibility );
        
        $stopWordThresholdValue = 100;
        if ( $ini->hasVariable( 'SearchSettings', 'StopWordThresholdValue' ) )
            $stopWordThresholdValue = $ini->variable( 'SearchSettings', 'StopWordThresholdValue' );

        $stopWordThresholdPercent = 60;
        if ( $ini->hasVariable( 'SearchSettings', 'StopWordThresholdPercent' ) )
            $stopWordThresholdPercent = $ini->variable( 'SearchSettings', 'StopWordThresholdPercent' );

        $searchThresholdValue = $totalObjectCount;
        if ( $totalObjectCount > $stopWordThresholdValue )
        {
            $searchThresholdValue = (int)( $totalObjectCount * ( $stopWordThresholdPercent / 100 ) );
        }

        foreach ( $searchPartsArray as $searchPart )
        {

            // do not search words that are too frequent
            if ( $searchPart['object_count'] < $searchThresholdValue )
            {
                $tmpTableCount++;
                $searchPartText = $searchPart['sql_part'];
                $table = $db->generateUniqueTempTableName( 'ezsearch_tmp_%', $i );
                $this->saveCreatedTempTableName( $i, $table );
                $tmpTable0 = array('from' => '','where' => '');
                if ( $i != 0 )
                {
                    $tmpTable0Name = $this->getSavedTempTableName( 0 );
                    $tmpTable0['from'] = ',' . $tmpTable0Name;
                    $tmpTable0['where'] = $tmpTable0Name . '.contentobject_id=ezsearch_object_word_link.contentobject_id AND';
                }
                $db->createTempTable( "CREATE TEMPORARY TABLE $table ( contentobject_id int primary key not null, published int, frequency float )" );
                $db->query( "INSERT INTO $table SELECT DISTINCT ezsearch_object_word_link.contentobject_id, ezsearch_object_word_link.published, sum(ezsearch_object_word_link.frequency) AS frequency
                                 FROM
                                     ezcontentobject,
                                     ezsearch_object_word_link
                                     $subTreeTable,
                                     ezcontentclass,
                                     ezcontentobject_tree
                                     $tmpTable0[from]
                                     $sqlPermissionChecking[from]
                                 WHERE
                                       $tmpTable0[where]
                                       $searchDateQuery
                                       $sectionQuery
                                       $classQuery
                                       $classAttributeQuery
                                       $searchPartText
                                       $subTreeSQL
                                       ezcontentobject.id=ezsearch_object_word_link.contentobject_id and
                                       ezcontentobject.contentclass_id = ezcontentclass.id and
                                       ezcontentclass.version = '0' and
                                       ezcontentobject.id = ezcontentobject_tree.contentobject_id and
                                       ezcontentobject_tree.node_id = ezcontentobject_tree.main_node_id
                                       $showInvisibleNodesCond
                                       $sqlPermissionChecking[where]
                                 GROUP BY ezsearch_object_word_link.contentobject_id, ezsearch_object_word_link.published",
                                 eZDBInterface::SERVER_SLAVE );
                $i++;
            }
            else
            {
                $stopWordArray[] = array( 'word' => $searchPart['text'] );
            }
        }

        if ( count( $searchPartsArray ) === 0 && $this->TempTablesCount == 0 )
        {
             $table = $db->generateUniqueTempTableName( 'ezsearch_tmp_%', 0 );
             $this->saveCreatedTempTableName( 0, $table );
             $db->createTempTable( "CREATE TEMPORARY TABLE $table ( contentobject_id int primary key not null, published int, frequency float )" );
             $db->query( "INSERT INTO $table SELECT DISTINCT ezsearch_object_word_link.contentobject_id, ezsearch_object_word_link.published, sum(ezsearch_object_word_link.frequency) AS frequency
                                 FROM ezcontentobject,
                                      ezsearch_object_word_link
                                      $subTreeTable,
                                      ezcontentclass,
                                      ezcontentobject_tree
                                      $sqlPermissionChecking[from]
                                 WHERE
                                      $searchDateQuery
                                      $sectionQuery
                                      $classQuery
                                      $classAttributeQuery
                                      $subTreeSQL
                                      ezcontentobject.id=ezsearch_object_word_link.contentobject_id and
                                      ezcontentobject.contentclass_id = ezcontentclass.id and
                                      ezcontentclass.version = '0' and
                                      ezcontentobject.id = ezcontentobject_tree.contentobject_id and
                                      ezcontentobject_tree.node_id = ezcontentobject_tree.main_node_id
                                      $showInvisibleNodesCond
                                      $sqlPermissionChecking[where]
                                      GROUP BY ezsearch_object_word_link.contentobject_id, ezsearch_object_word_link.published",
                                      eZDBInterface::SERVER_SLAVE );
             $this->TempTablesCount = 1;
             $i = $this->TempTablesCount;
        }

        $nonExistingWordCount = count( array_unique( $searchWordArray ) ) - count( $wordIDHash ) - $wildCardCount;
        $excludeWordCount = $searchWordCount - count( $stopWordArray );

        if ( ( count( $stopWordArray ) + $nonExistingWordCount ) == $searchWordCount && $this->TempTablesCount == 0 )
        {
            // No words to search for, return empty result

            // cleanup temp tables
            $db->dropTempTableList( $sqlPermissionChecking['temp_tables'] );
            $db->dropTempTableList( $this->getSavedTempTablesList() );

            return array( 'SearchResult' => array(),
                      'SearchCount' => 0,
                      'StopWordArray' => $stopWordArray );
        }
        $tmpTablesFrom = '';
        $tmpTablesWhere = '';
        /// tmp tables
        $tmpTableCount = $i;
        $relevanceSQL = ', ( (';
        for ( $i = 0; $i < $tmpTableCount; $i++ )
        {
            $tmpTableName = $this->getSavedTempTableName( $i );
            $tmpTablesFrom .= $tmpTableName;
            $relevanceSQL .= "$tmpTableName.frequency";
            if ( $i < ( $tmpTableCount - 1 ) )
            {
                $tmpTablesFrom .= ', ';
                $relevanceSQL .= ' + ';
            }
        }
        // This algorithm allows treating new objects as more relevant (decay calculation)
        // DecayDays: N days after an object was last modified. During these days the object
        // will gradually become less relevant. After this, it will not decay any further.
        $searchIni = eZINI::instance( 'search.ini' );
        $decayDays = (int) $searchIni->variable( 'Search', 'DecayDays' );
        $decayFactor = (float) $searchIni->variable( 'Search', 'DecayFactor' );
        if ( $decayFactor )
        {
            $relevanceSQL .= ") / $tmpTableCount ) +
                               ( greatest( 0, $decayDays -
                                              floor( (" . time() . " - ezcontentobject.modified) / 86400 )
                                         ) / $decayDays
                               ) * $decayFactor as ranking";
        }
        else
        {            
            // This algorithm has no decay calculation
            $relevanceSQL .= ') ) as ranking';
        }

        $tmpTablesSeparator = '';
        if ( $tmpTableCount > 0 )
        {
            $tmpTablesSeparator = ',';
        }

        $tmpTable0 = $this->getSavedTempTableName( 0 );
        for ( $i = 1; $i < $tmpTableCount; $i++ )
        {
            $tmpTableI = $this->getSavedTempTableName( $i );
            $tmpTablesWhere .= " $tmpTable0.contentobject_id=$tmpTableI.contentobject_id  ";
            if ( $i < ( $tmpTableCount - 1 ) )
                $tmpTablesWhere .= " AND ";
        }
        $tmpTablesWhereExtra = '';
        if ( $tmpTableCount > 0 )
        {
            $tmpTablesWhereExtra = "ezcontentobject.id=$tmpTable0.contentobject_id AND";
        }

        $and = '';
        if ( $tmpTableCount > 1 )
            $and = ' AND ';

        // Generate ORDER BY SQL
        $orderBySQLArray = $this->buildSortSQL( $sortArray );
        $orderByFieldsSQL = $orderBySQLArray['sortingFields'];
        $sortWhereSQL = $orderBySQLArray['whereSQL'];
        $sortFromSQL = $orderBySQLArray['fromSQL'];

        // Fetch data from table
        $searchQuery = '';
        $dbName = $db->databaseName();
        if ( $dbName == 'mysql' )
        {
            $searchQuery = "SELECT DISTINCT ezcontentobject.*, ezcontentclass.serialized_name_list as class_serialized_name_list, ezcontentobject_tree.*
                        $versionNameTargets
                        $relevanceSQL
                FROM
                   $tmpTablesFrom $tmpTablesSeparator
                   ezcontentobject,
                   ezcontentclass,
                   ezcontentobject_tree
                   $versionNameTables
                   $sortFromSQL
                WHERE
                $tmpTablesWhere $and
                $tmpTablesWhereExtra
                ezcontentobject.contentclass_id = ezcontentclass.id and
                ezcontentclass.version = '0' and
                ezcontentobject.id = ezcontentobject_tree.contentobject_id and
                ezcontentobject_tree.node_id = ezcontentobject_tree.main_node_id
                $versionNameJoins
                $showInvisibleNodesCond
                $sortWhereSQL
                ORDER BY $orderByFieldsSQL";
            if ( $tmpTableCount == 0 )
            {
                $searchCountQuery = "SELECT count( DISTINCT ezcontentobject.id ) AS count
                FROM
                   ezcontentobject,
                   ezcontentclass,
                   ezcontentobject_tree
                   $versionNameTables
                WHERE
                $emptyWhere
                ezcontentobject.contentclass_id = ezcontentclass.id and
                ezcontentclass.version = '0' and
                ezcontentobject.id = ezcontentobject_tree.contentobject_id and
                ezcontentobject_tree.node_id = ezcontentobject_tree.main_node_id
                $versionNameJoins
                $showInvisibleNodesCond
                $sortWhereSQL";
            }
        }
        else
        {
            $searchQuery = "SELECT DISTINCT ezcontentobject.*, ezcontentclass.serialized_name_list as class_serialized_name_list, ezcontentobject_tree.*
                        $versionNameTargets
                FROM
                   $tmpTablesFrom $tmpTablesSeparator
                   ezcontentobject,
                   ezcontentclass,
                   ezcontentobject_tree
                   $versionNameTables
                WHERE
                $tmpTablesWhere $and
                $tmpTablesWhereExtra
                ezcontentobject.contentclass_id = ezcontentclass.id and
                ezcontentclass.version = '0' and
                ezcontentobject.id = ezcontentobject_tree.contentobject_id and
                ezcontentobject_tree.node_id = ezcontentobject_tree.main_node_id
                $versionNameJoins
                 ";
            if ( $tmpTableCount == 0 )
            {
                $searchCountQuery = "SELECT count( DISTINCT ezcontentobject.id ) AS count
                FROM
                   ezcontentobject,
                   ezcontentclass,
                   ezcontentobject_tree
                   $versionNameTables
                WHERE
                ezcontentobject.contentclass_id = ezcontentclass.id and
                ezcontentclass.version = '0' and
                ezcontentobject.id = ezcontentobject_tree.contentobject_id and
                ezcontentobject_tree.node_id = ezcontentobject_tree.main_node_id
                $versionNameJoins
                 ";
            }
        }
        // Count query
        $where = 'WHERE';
        if ( $tmpTableCount == 1 )
            $where = '';
        if ( $tmpTableCount > 0 )
        {
            $searchCountQuery = "SELECT count( * ) AS count FROM $tmpTablesFrom $where $tmpTablesWhere ";
        }

        $objectRes = array();
        $searchCount = 0;

        if ( $nonExistingWordCount <= 0 )
        {
            // execute search query
            $objectResArray = $db->arrayQuery( $searchQuery, array( 'limit' => $searchLimit, 'offset' => $searchOffset ), eZDBInterface::SERVER_SLAVE );
            // execute search count query
                $objectCountRes = $db->arrayQuery( $searchCountQuery, array(), eZDBInterface::SERVER_SLAVE );
            $objectRes = eZContentObjectTreeNode::makeObjectsArray( $objectResArray );
            $searchCount = $objectCountRes[0]['count'];
        }
        else
            $objectRes = array();

        // Drop tmp tables
        $db->dropTempTableList( $sqlPermissionChecking['temp_tables'] );
        $db->dropTempTableList( $this->getSavedTempTablesList() );

        return array( 'SearchResult' => $objectRes,
                      'SearchCount' => $searchCount,
                      'StopWordArray' => $stopWordArray );
    }

    /*!
     \private
     \return an array of ORDER BY SQL
    */
    function buildSortSQL( $sortArray )
    {
        $sortCount = 0;
        $sortList = false;
        if ( isset( $sortArray ) and
             is_array( $sortArray ) and
             count( $sortArray ) > 0 )
        {
            $sortList = $sortArray;
            if ( count( $sortList ) > 1 and
                 !is_array( $sortList[0] ) )
            {
                $sortList = array( $sortList );
            }
        }
        $attributeJoinCount = 0;
        $attributeFromSQL = "";
        $attributeWereSQL = "";
        if ( $sortList !== false )
        {
            $sortingFields = '';
            foreach ( $sortList as $sortBy )
            {
                if ( is_array( $sortBy ) and count( $sortBy ) > 0 )
                {
                    if ( $sortCount > 0 )
                        $sortingFields .= ', ';
                    $sortField = $sortBy[0];
                    switch ( $sortField )
                    {
                        case 'path':
                        {
                            $sortingFields .= 'path_string';
                        } break;
                        case 'published':
                        {
                            $sortingFields .= 'ezcontentobject.published';
                        } break;
                        case 'modified':
                        {
                            $sortingFields .= 'ezcontentobject.modified';
                        } break;
                        case 'section':
                        {
                            $sortingFields .= 'ezcontentobject.section_id';
                        } break;
                        case 'depth':
                        {
                            $sortingFields .= 'depth';
                        } break;
                        case 'class_identifier':
                        {
                            $sortingFields .= 'ezcontentclass.identifier';
                        } break;
                        case 'class_name':
                        {
                            $classNameFilter = eZContentClassName::sqlFilter();
                            $sortingFields .= $classNameFilter['nameField'];
                            $attributeFromSQL .= ", $classNameFilter[from]";
                            $attributeWhereSQL .= "$classNameFilter[where] AND ";
                        } break;
                        case 'priority':
                        {
                            $sortingFields .= 'ezcontentobject_tree.priority';
                        } break;
                        case 'name':
                        {
                            $sortingFields .= 'ezcontentobject_name.name';
                        } break;
                        case 'ranking':
                        {
                            $sortingFields .= 'ranking';
                        } break;
                        case 'attribute':
                        {
                            $sortClassID = $sortBy[2];
                            // Look up datatype for sorting
                            if ( !is_numeric( $sortClassID ) )
                            {
                                $sortClassID = eZContentObjectTreeNode::classAttributeIDByIdentifier( $sortClassID );
                            }

                            $sortDataType = $sortClassID === false ? false : eZContentObjectTreeNode::sortKeyByClassAttributeID( $sortClassID );

                            $sortKey = false;
                            if ( $sortDataType == 'string' )
                            {
                                $sortKey = 'sort_key_string';
                            }
                            else
                            {
                                $sortKey = 'sort_key_int';
                            }

                            $sortingFields .= "a$attributeJoinCount.$sortKey";
                            $attributeFromSQL .= ", ezcontentobject_attribute as a$attributeJoinCount";
                            $attributeWereSQL .= " AND a$attributeJoinCount.contentobject_id = ezcontentobject.id AND
                                                  a$attributeJoinCount.contentclassattribute_id = $sortClassID AND
                                                  a$attributeJoinCount.version = ezcontentobject_name.content_version";

                            $attributeJoinCount++;
                        }break;

                        default:
                        {
                            eZDebug::writeWarning( 'Unknown sort field: ' . $sortField, 'eZContentObjectTreeNode::subTree' );
                            continue;
                        };
                    }
                    $sortOrder = true; // true is ascending
                    if ( isset( $sortBy[1] ) )
                        $sortOrder = $sortBy[1];
                    $sortingFields .= $sortOrder ? " ASC" : " DESC";
                    ++$sortCount;
                }
            }
        }

        // Should we sort?
        if ( $sortCount == 0 )
        {
            $sortingFields = " ranking DESC";
        }

        return array( 'sortingFields' => $sortingFields,
                      'fromSQL' => $attributeFromSQL,
                      'whereSQL' => $attributeWereSQL );
    }
}

?>