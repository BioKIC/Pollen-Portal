<?php
/**
 * This file contains code to read an rdf/xml file that uses OA, OAD, and dwcFP
 * as used in the New England Vascular Plants TCN.  For each OA:Annotation, this
 * code extracts the dwc and dwcFP occurrence data and populate PDOs reflecting 
 * Symbiota's Occurrence and Determination tables.  
 * 
 * The purpose of this file is to allow Symbiota records to be created from 
 * NEVP New Occurrence Annotation files uploaded with image links.  
 *
 * For an example new occurrence annotation in the expected form see: 
 * http://sourceforge.net/p/filteredpush/svn/2221/tree/FP-Design/trunk/ontologies/oa/NEVP_specimen_example.xml
 *
 * Example invocation: 
 *
 * $xml = XMLReader::open($file);
 * if ($xml->read()) {
 *    $hasAnnotation = $xml->lookupNamespace("oa");
 *    $hasDataAnnotation = $xml->lookupNamespace("oad");
 *    $hasdwcFP = $xml->lookupNamespace("dwcFP");
 *    // Note: contra the PHP xmlreader documentation, lookupNamespace
 *    // returns the namespace string not a boolean.
 *    if ($xml->name=="rdf:XML" && $hasAnnotation && $hasDataAnnotation && $hasdwcFP) {
 *       $processor = new NEVPProcessor();
 *       $result = $processor->process($file);
 *    }
 * }
 * 
 * @author Paul J. Morris
 */

include_once("$serverRoot/classes/OmOccurrences.php");
include_once("$serverRoot/classes/OmOccurDeterminations.php");
if (file_exists("$serverRoot/classes/Gazeteer.php")) { 
   include_once("$serverRoot/classes/Gazeteer.php");
}
include_once("$serverRoot/classes/iPlantUtility.php");
include_once("$serverRoot/classes/ImageShared.php");

define ("DEFAULT_NEVP_COUNTRY","United States of America");

// Check to see if OmOccurrences matches current schema version.
$testOcc = new OmOccurrences();
if (!$testOcc->checkSchema()) { 
   echo "[Warning: classes/OmOccurrences.php does not match the Symbiota schema version.]\n";
   echo "[Warning: Ingest of data may fail.  Contact a Symbiota developer.]\n";
}

if (!class_exists('Result')) {
    class Result {
        public $couldparse = false; // document compliant with expectations
	    public $success = false; // completed without errors
	    public $errors = "";     // error messages
        public $recordcount = 0;  // number of core records encountered
	    public $successcount = 0; // number of records successfully processed
	    public $failurecount = 0; // number of records with errors in processing
	    public $insertcount = 0; // number of records successfully inserted
	    public $updatecount = 0; // number of records successfully updated
    }
}

$result = new Result();

class EVENT { 
   public $eventdate;
   public $verbatimeventdate;
   public $startday;
   public $startmonth;
   public $startyear;

   public function setEventDate($date) { 
      $this->eventdate = $date;
      if (strlen($date)>3) { $this->startyear = substr($date,0,4);  }
      if (strlen($date)>6) { $this->startmonth = substr($date,5,2); }
      if (strlen($date)>9) { $this->startday = substr($date,8,2);   }
   }

   public function write() { 
      return "$this->verbatimeventdate [$this->eventdate]";
   } 
}

class IDENTIFICATION { 
   public $occid;
   public $family;
   public $taxonrank;
   public $scientificname;
   public $genus;
   public $specificepithet;
   public $identificationqualifier;
   public $scientificnameauthorship;
   public $infraspecificrank;
   public $infraspecificepithet;
   public $infraspecificauthor;
   public $nomenclaturalcode = "ICNAFP";
   public $identifiedby;
   public $dateidentified;
   public $typestatus;
   public $isfiledundernameincollection; 
   public $taxonid;

   public function write() { 
     $date = "";
     if ($this->dateidentified!= null) { $date = $this->dateidentified->writeAll(); }
     $id = new OmOccurDeterminations();
     $id->keyvalueset('occid',$this->occid);
     $id->keyvalueset('sciname',$this->scientificname);
     if (strlen($this->infraspecificauthor)>0) {
         $id->keyvalueset('scientificNameAuthorship',$this->infraspecificauthor);
     } else {
         $id->keyvalueset('scientificNameAuthorship',$this->scientificnameauthorship);
     }
     $code ="";
     if ($this->nomenclaturalcode!=null && strlen($this->nomeclaturalcode)>0) {
        $code = "[" + $this->nomenclaturalcode + "]";
     }
     $id->keyvalueset('identificationRemarks',$this->typestatus + $code);
     $id->keyvalueset('identifiedBy',$this->identifiedby);
     $id->keyvalueset('dateIdentified',$date);
     $id->keyvalueset('identificationQualifier',trim($this->identificationqualifier));
     // TODO: Handle taxonid in authorityfile

     $id->save();
   }

   public function getNameWithoutAuthor() { 
      $result = "";
      if (strpos($this->scientificname,$this->scientificnameauthorship)===FALSE) { 
           // scientificName doesn't contain authorship
     	   $result = $this->scientificname;
      } else { 
           // scientificName does contain authorship, remove
           $result = trim(substr($this->scientificname,0,strpos($this->scientificname,$this->scientificnameauthorship)-1));
      }       
      return $result;
   }

   public function getNameWithAuthor() { 
      $result = "";
      if (strpos($this->scientificname,$this->scientificnameauthorship)===FALSE) { 
           // scientificName doesn't contain authorship, add
     	   $result = trim($this->scientificname . " " . $this->scientificnameauthorship);
      } else { 
           $result = $this->scientificname;
      }       
      return $result;
   }

}

class ANNOTATION { 
   public $id;
   public $expectation;
   public $annotatedat;
   public $motivations = array();
   public $annotator;
}

class ANNOTATOR { 
  public $mbox_sha1sum;
  public $name;
  public $workplacehomepage;
}

class OCCURRENCE { 
   public $occurrenceid = null;
   public $basisofrecord = "PreservedSpecimen";
   public $collectioncode = null;
   public $collectionid;
   public $institutioncode = null;
   public $catalognumber;
   public $datelastmodified;
   public $identifications = array();
   public $recordedby;
   public $recordnumber; // collectornumber
   public $country = null;
   public $stateprovince;
   public $county;
   public $municipality;
   public $locality = null;
   public $notes;
   public $minimumelevationinmeters = null;
   public $maximumelevationinmeters = null;
   public $collectingevent = null;
   public $datemodified;
   public $storagelocation;
   public $associatedmedia = array();
   public $recordenteredby;

   public function getFiledUnderID() { 
        $result = null;
        foreach($this->identifications as $id) { 
           if ($result==null) { 
              $result = $id;
           }
           if ($id->isfiledundernameincollection==$this->collectioncode) { 
              $result = $id;
           }
        }
        return $result;
   }

   public function write() { 
       global $createNewRec, $result; 
       $occ = new OmOccurrences();
       $det = new OmOccurDeterminations();
       $imageHandler = new ImageShared();
       $collid = $occ->lookupCollId($this->institutioncode, $this->collectioncode);
       $collreportmatch = "collid=[$collid], institutionCode=[$this->institutioncode], collectionCode=[$this->collectioncode]\n";
       // Record exists if there is a matching DarwinCore triplet.  
       $exists = $occ->loadByDWCTriplet($this->institutioncode, $this->collectioncode, $this->catalognumber);
       if (!$exists) {
           // Image upload process in TcnImageTools creates omoccurrences records where 
           // collectioncode is not populated, but collid is. Need to handle both cases.
           $exists = $occ->loadByCollidCatalog($collid, $this->catalognumber);
           // TODO: Question for Ed: Find out if institutioncode and collectioncode should be populated if we
           // end up here.
       }
       if (!$exists) { 
       	  $occ = new OmOccurrences();
          $occ->setcollid($collid);
          $occ->setcollectionCode($this->collectioncode);
          $occ->setinstitutionCode($this->institutioncode);
          $occ->setcatalogNumber($this->catalognumber);
          $occ->setprocessingStatus("unprocessed");
          // Provide for link to authoritative database record (which doesn't yet exist).
          $occ->setdbpk($this->collectioncode . $this->catalognumber);
          // if creating a new occurrence record, assume it was created by the annotator on the dc:created date.
          $occ->setrecordenteredby($this->recordenteredby);
          $occ->setdateentered($this->datemodified);
       }
       $occ->setoccurrenceId($this->occurrenceid);
       $occ->setbasisOfRecord($this->basisofrecord);
       // TODO: Handle datemodified
       // TODO: Lookup collector with botanist guid
       // TODO: Split collectors on pipe.
       if (strpos(";",$this->recordedby)>0) { 
           // split on first semicolon.
           $occ->setrecordedBy(substr($this->recordedby,0,strpos(";",$this->recordedby)));
           $occ->setassociatedCollectors(substr($this->recordedby,strpos(";",$this->recordedby),strlen($this->recordedby)));
       } else { 
           $occ->setrecordedBy($this->recordedby);
           $occ->setassociatedCollectors("");
       }
       if ($this->recordnumber!=null) { $occ->setrecordNumber($this->recordnumber); }
       $occ->settypeStatus($this->getTypeStatusList());
       if ($this->country==null) { 
          $occ->setcountry(DEFAULT_NEVP_COUNTRY);
       } else {
          $occ->setcountry($this->country);
       } 
       if ($this->stateprovince!=null) { $occ->setstateProvince($this->stateprovince); }
       if ($this->county!=null) { $occ->setcounty($this->county); }
       $occ->setmunicipality($this->municipality);
       if ($this->locality!=null) { $occ->setlocality($this->locality); } 
       if ($this->collectingevent != null) { 
          // Symbiota event date is a mysql date field, thus less 
          // expressive than an ISO date field.
          if (strlen($this->collectingevent->eventdate)>10) { 
              // TODO: Handle more cases of ISO date ranges.
              $occ->seteventDate(substr($this->collectingevent->eventdate,0,10));
          } else { 
              $occ->seteventDate($this->collectingevent->eventdate);
          }
          $occ->setyear($this->collectingevent->startyear);
          $occ->setmonth($this->collectingevent->startmonth);
          $occ->setday($this->collectingevent->startday);
          $occ->setverbatimEventDate($this->collectingevent->verbatimeventdate);
       }
       if (class_exists('Gazeteer')) {
          $gazeteer = new Gazeteer();
          // For NEVP, lookup georeference from state+municipality
          $georef = $gazeteer->lookup($this->stateprovince, $this->municipality,$occ->getyear());
          if ($georeference->foundMatch===true) { 
             $occ->setdecimalLatitude($georef->latitude);
             $occ->setdecimalLongitude($georef->longitude);
             $occ->setcoordinateUncertaintyInMeters($georef->errorRadius);
             $occ->setgeodeticDatum($georef->geodeticDatum);
             $occ->setgeoreferencedBy($georef->georeferencedBy);
             $occ->setgeoreferenceProtocol($georef->georeferenceProtocol);
             $occ->setgeoreferenceSources($georef->georeferenceSources);
          }
       }
       if ($this->minimumelevationinmeters!=null) { $occ->setminimumElevationInMeters($this->minimumelevationinmeters); }
       if ($this->maximumelevationinmeters!=null) { $occ->setmaximumElevationInMeters($this->maximumelevationinmeters); }
       $filedUnder = $this->getFiledUnderID();
       if ($filedUnder!=null) { 
           $occ->setsciname($filedUnder->getNameWithoutAuthor());  // without author
           $occ->setscientificName($filedUnder->getNameWithAuthor());  // with author
           if ($det->lookupAcceptedTID($filedUnder->scientificname)>0) {
               $occ->settidinterpreted($det->lookupAcceptedTID($filedUnder->scientificname));
           }
           $occ->setfamily($filedUnder->family);
           if (strlen($occ->getfamily())==0) {
               $occ->setfamily($det->lookupFamilyForTID($det->lookupTID($filedUnder->scientificname)));
           }
           $occ->setgenus($filedUnder->genus);
           $occ->setspecificEpithet($filedUnder->specificepithet);
           $occ->setinfraSpecificEpithet($filedUnder->infraspecificepithet);
           $occ->setidentificationQualifier($filedUnder->identificationqualifier);
           $occ->setscientificNameAuthorship($filedUnder->scientificnameauthorship);
           // work out value for taxon rank.
           $occ->settaxonRank($filedUnder->infraspecificrank);
           if (strlen($filedUnder->infraspecificepithet)>0 && strlen($filedUnder->infraspecificrank)==0) {
                $occ->settaxonRank("subspecies");
           }
           if (strlen($filedUnder->infraspecificepithet)==0 && strlen($filedUnder->specificepithet)>0) {
                $occ->settaxonRank("species");
           }
           if (strlen($filedUnder->infraspecificepithet)==0 && strlen($filedUnder->specificepithet)==0 && strlen($filedUnder->genus)>0) {
                $occ->settaxonRank("genus");
           }
           if ($filedUnder->dateidentified!=NULL) {
               $occ->setdateIdentified($filedUnder->identificationdate->writeAll());
           }
       }
       // TODO: Handle motivations (transcribing and NSF abstract numbers).

       
       if (!$exists || $createNewRec==1) {
           // if record exists, then TcnImageConf variable createNewRec specifies policy for update.
           $saveresult = $occ->save();
           $occid = $occ->getoccid();
           if ($saveresult===TRUE) {
               if ($exists) {
                   // echo "Updated occid: [".$occ->getoccid()."]\n";
                   $result->updatecount++;
               } else {
                   // echo "Added occid: [".$occ->getoccid()."]\n";
                   $result->insertcount++;
               }
               foreach($this->identifications as $id) {
                    $id->occid = $occ->getoccid();
                    $id->write();
               }
               // Create image records from associatedmedia array.
               foreach($this->associatedmedia as $media) {
                  $mediaguid = $media->guid;
                  foreach($media->accesspoints as $accesspoint) {  
                     $imageresult = getiPlantIDForNEVP($accesspoint->accessURI);
                     if ($imageresult->statusok===FALSE) { 
                        echo "Error: " . $imageresult->error . "\n";
                     } else { 
echo "[$accesspoint->format]";
                        $iPlantID = $imageresult->resource_uniq;
                        if ($accesspoint->format=="dng") { 
                           // Original dng file
                           $sourceUrl = "http://bovary.iplantcollaborative.org/image_service/image/$iPlantID";
                        } 
                        if ($accesspoint->format=="jpg") { 
                           // Preconstructed derivative JPG file
                           $imgWebUrl = "http://bovary.iplantcollaborative.org/image_service/image/$iPlantID?rotate=guess&resize=1250&format=jpeg,quality,100";
                           $imgTnUrl = "http://bovary.iplantcollaborative.org/image_service/image/$iPlantID?rotate=guess&resize=150&format=jpeg,quality,75";
                           $imgLgUrl = "http://bovary.iplantcollaborative.org/image_service/image/$iPlantID?rotate=guess&format=jpeg,quality,100";
                        } 
                     }
                  }
                  $tid = $det->lookupTID($filedUnder->scientificname);
                  $caption = "$this->collectioncode $this->catalognumber $filedUnder->scientificname";
                  $locality = $occ->getcountry() ." $this->stateprovince $this->county $this->municipality $this->locality";
                  $sortsequence = 50;  // default number
                  $copyright = $media->rights;  
                  $owner = $media->owner;        
                  $notes = $media->usageterms;    
echo "[$imgWebUrl][$occid]";
                  $imgid = $imageHandler->getImgIDForSourceURL($sourceUrl); 
                  if ($imgid=="") { 
                     // add this image record
	                 $isaveresult = $imageHandler->databaseImageRecord($imgWebUrl,$imgTnUrl,$imgLgUrl,$tid,$caption,$this->recordenteredby,null,$sourceUrl,$copyright,$owner,$locality,$occid,$notes,$sortsequence,"specimen","");
                  } else { 
	                 $isaveresult = $imageHandler->updateImageRecord($imgid,$imgWebUrl,$imgTnUrl,$imgLgUrl,$tid,$caption,$this->recordenteredby,null,$sourceUrl,$copyright,$owner,$locality,$occid,$notes,$sortsequence,"specimen","");
                  } 
echo "[$isaveresult]";
               }

               $result->successcount++;
           } else {
               $result->errors .= "Error: [" . $occ->errorMessage() . "]\n";
               if ($occ->errorMessage()=="Cannot add or update a child row: a foreign key constraint fails (`symbiota`.`omoccurrences`, CONSTRAINT `FK_omoccurrences_collid` FOREIGN KEY (`collid`) REFERENCES `omcollections` (`CollID`) ON DELETE CASCADE ON UPDATE CASCADE)") { 
                   $result->errors .= "Interpretation: Record contains a collectionCode and institutionCode combination which was not found in omcollections (or only a collectionCode that was not found in omcollections or omoccurrences). \n";
                   $result->errors .= $collreportmatch;
               } 
               $result->failurecount++;
           }
       } else {
           echo "Skipping, record exists and specified policy is to not update. [".$occ->getoccid()."]\n";
       }
       

   }

   public function addIdentification($id) { 
      $this->identifications[] = clone $id;
   }

   public function getTypeStatusList() {
      $result = "";
      $statusValues = array();
      $comma = "";
      foreach ($this->identifications as $id) {
          if(strlen($id->typestatus)>1) {
             if (!array_key_exists($id->typestatus,$statusValues)) {
                $statusValues[$id->typestatus]=1;
                $result = $comma . $id->typestatus;
                $comma = ",";
             }
          }
      }
      return $result;
   }


}

class ASSOCIATEDMEDIA { 
   public $guid;
   public $accesspoints;
   public $rights;
   public $owner;
   public $usageterms;
}

class ACCESSPOINT { 
   public $variant;
   public $accessURI;
   public $format;
   public $hashFunction;
   public $hashValue;
}

class NEVPProcessor { 
    
    public $currentAnnotation = null;
    public $currentAnnotator = null;
    public $cureentMedia = null;
    public $cureentAP = null;
    public $currentOcc = null;
    public $currentId = null;
    public $currentDate = null;
    public $currentTag = "";
    public $acount = 0;       // number of annotations expected
    public $countfound = 0;  // number of annotations found  
    
    function startElement($parser, $name, $attrs) {
        global $depth, $currentOcc, $currentTag, $currentId, $currentDate, $currentAnnotator, $currentAnnotation, $currentMedia, $currentAP;
        $currentTag = $name;
        
// echo "[$name]";
        switch ($name) { 
           case "OA:ANNOTATION":
              $currentAnnotation = new ANNOTATION();
              $currentAnnotation->id = $attrs['RDF:ABOUT'];
              break;
           case "OAD:EXPECTATION_INSERT":
              $currentAnnotation->expectation = $name;
              break;
           case "OAD:EXPECTATION_UPDATE":
              $currentAnnotation->expectation = $name;
              break;
           case "OAD:TRANSCRIBING":
           case "OA:TRANSCRIBING":
              $currentAnnotation->motivations[] = $name;
              break;
           case "OA:MOTIVATEDBY":
              if (@strlen($attrs['RDF:RESOURCE'])>0) { 
                  $currentAnnotation->motivations[] = $attrs['RDF:RESOURCE'];
              }
              break;
           case "OA:ANNOTATEDBY":
              $currentAnnotator = new ANNOTATOR();
              break;
           case "DWCFP:OCCURRENCE":
           case "DWCFP:OCCURENCE":
              $currentOcc = new OCCURRENCE();
              $currentOcc->occurrenceid = $attrs['RDF:ABOUT'];
              break;
           case "DWCFP:IDENTIFICATION":
              $currentId = new IDENTIFICATION();
              break;
           case "DWCFP:EVENT":
              $currentDate = new EVENT();
              break; 
           case "DWCFP:HASCOLLECTIONBYID": 
              $currentOcc->collectionid = $attrs['RDF:RESOURCE'];
              break;
           case "DWCFP:HASASSOCIATEDMEDIA":
              $currentMedia = new ASSOCIATEDMEDIA();
              break;
           case "DCMITYPE:IMAGE":
              $currentMedia->guid = $attrs['RDF:ABOUT'];
              break;
           case "AC:HASACCESSPOINT":
              $currentAP = new ACCESSPOINT();
              break;
           case "DWCFP:HASBASISOFRECORD": 
              $basis = $attrs['RDF:RESOURCE'];
              if (strpos($basis,'http://')!==false) { 
                  $bits = explode('/',$basis);
                  $basis = $bits[count($bits)-1];
              }
              $currentOcc->basisofrecord = $basis;
              break;
        }  
  
        if (!in_array($parser,$depth)) { 
          $depth[$parser] = 0;
        }
        $depth[$parser]++;
    }
    
    function endElement($parser, $name) {
        global $depth, $currentOcc, $currentId, $currentDate, $result, $currentAnnotator, $currentAnnotation, $currentMedia, $currentAP;
        $depth[$parser]--;
        
        switch ($name) { 
           case "DWCFP:OCCURRENCE":
           case "DWCFP:OCCURENCE":
              // Nothing to do, assuming one occurrence per annotation.
              $result->recordcount++;
              break;
           case "DWCFP:IDENTIFICATION":
              $currentOcc->addIdentification($currentId);
              $currentId = null;
              break;
           case "DWCFP:HASCOLLECTINGEVENT":
              $currentOcc->collectingevent = clone $currentDate;
              $currentDate = null;
              break;
           case "OA:ANNOTATION":
              $this->countfound++;
              $currentOcc->recordenteredby=$currentAnnotation->annotator->name;
              $currentOcc->write();
              $currentOcc = null;
              break;
           case "OA:ANNOTATEDBY":
              $currentAnnotation->annotator = $currentAnnotator;
              $currentAnnotator = null;
              break;
           case "DWCFP:HASASSOCIATEDMEDIA":
              $currentOcc->associatedmedia[] = clone $currentMedia;
              $currentMedia = null;
              break;
           case "AC:HASACCESSPOINT":
              $currentMedia->accesspoints[] = clone $currentAP;
              $currentAP = null;
              break;
        }
    }
    
    function value($parser, $data) { 
       global $depth, $currentOcc, $currentTag, $currentId, $currentDate, $currentAnnotator, $currentAnnotation, $currentMedia,$currentAP;
    
// Top level of document: [RDF:RDF][RDF:DESCRIPTION][RDFS:COMMENT][RDFS:COMMENT][CO:COUNT]
// Annotation: [OA:ANNOTATION][OA:HASTARGET][OA:SPECIFICRESOURCE][OA:HASSELECTOR][OAD:KVPAIRQUERYSELECTOR][DWC:COLLECTIONCODE][DWC:INSTITUTIONCODE][OA:HASSOURCE][OA:HASBODY]
// New Occurrence: [DWCFP:OCCURRENCE][DC:TYPE][DWCFP:HASBASISOFRECORD][DWC:CATALOGNUMBER][DWCFP:HASCOLLECTIONBYID][DWC:COLLECTIONCODE][DWCFP:HASIDENTIFICATION][DWCFP:IDENTIFICATION][DWCFP:ISFILEDUNDERNAMEINCOLLECTION][DWC:SCIENTIFICNAME][DWC:GENUS][DWC:SPECIFICEPITHET][DWCFP:INFRASPECIFICRANK][DWC:INFRASPECIFICEPITHET][DWC:SCIENTIFICNAMEAUTHORSHIP][DWC:IDENTIFICATIONQUALIFIER][DWCFP:USESTAXON][DWCFP:TAXON][DWCFP:HASTAXONID][DWC:RECORDEDBY][DWC:RECORDNUMBER][DWCFP:HASCOLLECTINGEVENT][DWCFP:EVENT][DWC:EVENTDATE][DWC:VERBATIMEVENTDATE][DWC:COUNTRY][DWC:STATEPROVINCE][DWC:COUNTY][DWC:MUNICIPALITY][DC:CREATED][DWC:MODIFIED][OBO:OBI_0000967][DWCFP:HASASSOCIATEDMEDIA]
// Annotation, following occurrence in body: [OAD:HASEVIDENCE][OAD:HASEXPECTATION][OAD:EXPECTATION_INSERT][OA:MOTIVATEDBY][OAD:TRANSCRIBING][OA:MOTIVATEDBY][OA:ANNOTATEDBY][FOAF:PERSON][OA:ANNOTATEDAT][OA:SERIALIZEDBY][FOAF:AGENT][FOAF:NAME][OA:SERIALIZEDAT]
// [DC:TYPE] -- nowhere to put in symbiota
// [DWCFP:HASIDENTIFICATION] -- nowhere to put in symbiota
// TODO: Extract annotator and serializer
       if ($data!=null) { $data = trim($data); } 
       switch ($currentTag) {
         case "CO:COUNT":
             if (strlen($data)>0) { 
                $this->acount = $data;
             }
             break;

         case "DWC:INSTITUTIONCODE": 
             $currentOcc->institutioncode .= $data;
             break;
         case "DWC:COLLECTIONCODE": 
             $currentOcc->collectioncode .= $data;
             break;
         case "DWC:CATALOGNUMBER": 
             $currentOcc->catalognumber .= $data;
             break;
         case "DC:CREATED":
             $currentOcc->datelastmodified .= $data;
             break;
         case "DWC:RECORDEDBY":
             $currentOcc->recordedby .= $data;
             break;
         case "DWC:RECORDNUMBER":
             $currentOcc->recordnumber .= $data;
             break;
         case "DWC:COUNTRY":
             if ($data=="United States") { 
                 $currentOcc->country .= DEFAULT_NEVP_COUNTRY;
             } else { 
                 $currentOcc->country .= $data;
             }
             break;
         case "DWC:STATEPROVINCE":
             $currentOcc->stateprovince .= $data;
             break;
         case "DWC:COUNTY":
             $currentOcc->county .= $data;
             break;
         case "DWC:MUNICIPALITY":
             $currentOcc->municipality .= $data;
             break;
         case "DWC:LOCALITY":
             $currentOcc->locality .= $data;
             break;
         case "NOTES":
             $currentOcc->notes .= $data;
             break;
         case "DWC:MINIMUMELEVATIONINMETERS":
             $currentOcc->minimumelevationinmeters .= $data;
             break;
         case "DWC:MAXIMUMELEVATIONINMETERS":
             $currentOcc->maximumelevationinmeters .= $data;
             break;
         case "DWC:MODIFIED":
             $currentOcc->datemodified .= $data;
             break;
         case "OBO:OBI_0000967":
             $currentOcc->storagelocation .= $data;
             break;

         case "DWC:SCIENTIFICNAME":
             $currentId->scientificname .= $data;
             break;
         case "DWC:FAMILY":
             $currentId->family .= $data;
             break;
         case "DWC:GENUS":
             $currentId->genus .= $data;
             break;
         case "DWC:IDENTIFICATIONQUALIFIER":
             $currentId->identificationqualifier .= $data;
             break;
         case "DWC:SPECIFICEPITHET":
             $currentId->specificepithet .= $data;
             break;
         case "DWC:SCIENTIFICNAMEAUTHORSHIP":
             $currentId->scientificnameauthorship .= $data;
             break;
         case "DWC:INFRASPECIFICEPITHET":
             $currentId->infraspecificepithet .= $data;
             break;
         case "DWCFP:INFRASPECIFICRANK":
             $currentId->infraspecificrank .= $data;
             break;
         case "DWC:NOMENCLATURALCODE":
             $currentId->nomenclaturalcode .= $data;
             break;
         case "DWC:IDENTIFIEDBY":
             $currentId->identifier .= $data;
             break;
         case "DWC:DATEIDENTIFED":
             $currentId->dateidentified .= $data;
             break;
         case "DWCFP:HASTAXONID":
             $currentId->taxonid .= $data;
             break;
         case "DWCFP:ISFILEDUNDERNAMEINCOLLECTION":
             $currentId->isfiledundernameincollection .= $data;
             break;
         case "DWC:TYPESTATUS":
             $currentId->typestatus .= $data;
             break;
    
         // [Collecting] Event
         case "DWC:EVENTDATE":
             if (strlen(trim($data))>0) { 
                $currentDate->setEventDate($data);
             }
             break;
         case "DWC:VERBATIMEVENTDATE":
             $currentDate->verbatimeventdate .= $data;
             break;
         case "DWC:YEAR":
             $currentDate->startyear .= $data;
             break;
         case "DWC:DAY":
             $currentDate->startday .= $data;
             break;
         case "DWC:MONTH":
             $currentDate->startmonth .= $data;
             break;

         // Annotator
         case "FOAF:NAME":
             if ($currentAnnotator!=null) { 
                // we are inside the annotated by, not serialized by
                $currentAnnotator->name .= $data;
             } 
             break;
         case "FOAF:WORLPLACEHOMEPAGE":
             if ($currentAnnotator!=null) { 
                // we are inside the annotated by, not serialized by
                $currentAnnotator->workplacehomepage .= $data;
             } 
             break;
         case "FOAF:MBOX_SHA1SUM":
             if ($currentAnnotator!=null) { 
                // we are inside the annotated by, not serialized by
                $currentAnnotator->mbox_sha1sum .= $data;
             } 
             break;

         // AssociatedMedia Image
         case "DC:RIGHTS":
             $currentMedia->rights .= $data;
             break;
         case "XMPRIGHTS:OWNER":
             $currentMedia->owner .= $data;
             break;
         case "XMPRIGHTS:USAGETERMS":
             $currentMedia->usageterms .= $data;
             break;
          
         // AssociatedMedia Image AccessPoint
         case "AC:VARIANT":
             $currentAP->variant .= $data;
             break;
         case "AC:ACCESSURI":
             $currentAP->accessURI .= $data;
             break;
         case "DC:FORMAT":
             $currentAP->format .= $data;
             break;
         case "AC:HASHFUNCTION":
             $currentAP->hashFunction .= $data;
             break;
         case "AC:HASHVALUE":
             $currentAP->hashValue .= $data;
             break;
       }
    
    }
    
    public function process($file) {
        global $result,$depth;
        $result = new Result();
        $result->couldparse = true;

        $parser = xml_parser_create('UTF-8');

        $depth = array();

        xml_set_element_handler($parser, "NEVPProcessor::startElement", "NEVPProcessor::endElement");
        xml_set_character_data_handler($parser, "NEVPProcessor::value");
        
        if (!($fp = fopen($file, "r"))) {
            $result->couldparse = false;
        }
        
        while ($data = fread($fp, 4096)) {
            if (!xml_parse($parser, $data, feof($fp))) {
                $result->couldparse = false;
                // sprintf("XML error: %s at line %d", xml_error_string(xml_get_error_code($xml_parser)), xml_get_current_line_number($xml_parser)));
            }
        }

        if ($this->countfound!=$this->acount) { 
           // TODO: Report discrepancy.
           echo "ERROR: Expected $this->acount annotations, found $this->countfound \n";
        }
    
        xml_parser_free($parser);
    
        return $result;
    }
    
}

?>
