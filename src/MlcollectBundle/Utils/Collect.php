<?php

namespace MlcollectBundle\Utils;

class Collect {
    
    private $mailbox;
    private $osmbcToken;
    private $fromName;
    private $fromEmail;
    private $currentAuthorMailaddress;
    
    //
    public function __construct($imap_server, $imap_box, $imap_user, $imap_password, $osmbc_token, $from_email, $from_name) {

        $this->mailbox = new \PhpImap\Mailbox('{' . $imap_server . '}' . $imap_box, $imap_user, $imap_password);      
        $this->osmbcToken = $osmbc_token;
        $this->fromName = $from_name;
        $this->fromEmail = $from_email;
    }
    
    public function collect() {
        
        $datas = $this->getCollectDatas();
        
        foreach ($datas as $key => $data) {
            
            $datas[$key]['result'] = $this->postDataToTbc($data);
        }
        
        return $datas;
    }
    
    //
    private function postDataToTbc($data) {
               
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://thefive.info/api/collectArticle/' . $this->osmbcToken);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $result = curl_exec($ch);
        curl_close($ch);
            
        //osmbc returns error
        if ($result != 'Article Collected in TBC.') { 

            $this->sendError($result);
            
            return $result;
        }
        
        return $result;
    }
    
    /**
     * Parse the sent Emails and return pipermail links
     * 
     * @return array
     */
    private function getCollectDatas() {
        
        $mailIds = $this->mailbox->searchMailbox('UNSEEN');
        
        $datas = [];
        foreach ($mailIds as $mailId) {
            
            $mail = $this->mailbox->getMail($mailId);
            $datas[$mailId]['email'] = $mail->fromAddress;
            $this->currentAuthorMailaddress = $mail->fromAddress;            
            $link = $this->getLinkBySubject($mail->subject); 
            
            //error occured
            if (!$link) {
                
                continue;
            }            
            
            $markdown = $this->getMarkdownByTextplain($mail->textPlain);
            
            $datas[$mailId]['collection'] = $link;
            
            //replace link in markdown
            $markdown = preg_replace('!\+(.*)\+!', '[$1](' . $datas[$mailId]['collection'] . ')', $markdown);
            
            //set non default markdown language
            if (preg_match('!^(de|en|es|jp|fr)\:!i', $markdown, $match)) {
                
                //remove marker
                $markdown = preg_replace('!^(de|en|es|jp|fr)\:!i', '', $markdown);
                
                //save markdown in language var
                $datas[$mailId]['markdown' . strtoupper($match[1])] = $markdown;
            }
            
            //set default markdown language
            else {
                
                #todo: should be simply 'markdown' when api is updated
                $datas[$mailId]['markdown'] = $markdown;
            }
        }
        
        return $datas;
    }
    
    /**
     * Get markdown from mailbody if given
     * 
     * @param string $textPlain
     * @return string
     */
    private function getMarkdownByTextplain($textPlain) {
        
        $markdown = strtok($textPlain, "\n");
        
        #todo: quick and dirty fix for manfreds signature
        if ($markdown[0] == '#') {
            
            return '';
        }
        
        return $markdown;
    }
    
    /**
     * Try to find links by given subject
     * 
     * @param type $subject
     * @return string
     */
    private function getLinkBySubject($subject) {
        
        $slug = strtolower($this->getSlugBySubject($subject));
        
        //slug not found
        if (!$slug) {
            
            return false;
        }
        
        $title = $this->getTitleBySubject($subject);
        
        #todo: reformat link by maildate
        $link = 'https://lists.openstreetmap.org/pipermail/'.$slug.'/'.date('Y', time()).'-'.date('F', time()).'';
        $threadLink = $link . '/thread.html';
        
        $html = file_get_contents($threadLink);
        
        $crawler = new \Symfony\Component\DomCrawler\Crawler($html);
        
        $nodes = $crawler->filterXPath('//a[contains(text(), "'.$title.'")]');
        
        foreach ($nodes as $node) {
            
            $fileName = $node->getAttribute('href');
            break;
        }
        
        //message not found
        if (!isset($fileName)) {
            
            $this->sendError('A message with the title "' . $title . '" could not be found in the archive of the month ' . date('F', time()) . '/' . date('Y', time()) . '');
            
            return false;
        }
        
        return $link . '/' . $fileName;
    }
    
    /**
     * Return mailinglistSlug from mailSubject
     * 
     * @param String $subject
     * @return String
     */
    private function getSlugBySubject($subject) {
        
        preg_match('!\[(.*)\]!U', $subject, $slug);
        
        if (empty($slug)) {
            
            return false;
        }
        
        //test if slugs are allowed
        $allowedSlugs = [
            'tagging' => 'tagging',
            'hot' => 'hot',
            'talk-de' => 'talk-de',
            'talk-ch' => 'talk-ch', 
            'osm-talk' => 'talk',
            'talk-us' => 'talk-us',            
            'talk-br' => 'talk-br',
            'talk-pt' => 'talk-pt',
            'osm-dev' => 'osm-dev',
        ];
        
        if (!isset($allowedSlugs[strtolower($slug[1])])) {
            
            $this->sendError('Mailinglist slug "'.$slug[1].'" not on allowed yet.');
            
            return false;
        }
        
        return trim(strtolower($allowedSlugs[strtolower($slug[1])]));
    }
    
    /**
     * Return clean title from mailSubject
     * 
     * @param String $subject
     * @return String
     */
    private function getTitleBySubject($subject) {
        
        preg_match('!.*\[.*\](.*)!u', $subject, $title);
        
        if (empty($title)) {
            
            return NULL;
        }
        
        return trim($title[1]);
    }
    
    //swiftmailer not working
    private function sendErrorX($message, $authorMailaddress) {
        
        $mail = \Swift_Message::newInstance()
                ->setSubject('Could not collect your Collection')
                ->setTo($authorMailaddress)
                ->setFrom($this->getParameter('osmbc_from'))
                ->setBody('There appeared an error posting your collection to OSMBC TBC: ' . $message);
        
        $this->get('swiftmailer.mailer.osmbc')->send($mail);
    }
    
    //
    private function sendError($message) {

        echo $message . ' (!)<hr>';
        
        $mail = new \PHPMailer();
        $mail->From = $this->fromEmail;
        $mail->FromName = $this->fromName;
        $mail->addAddress($this->currentAuthorMailaddress);
        $mail->isHTML(false);
        $mail->Subject = "[osmbc-tbc] Could not collect your Collection";
        $mail->Body = 'There appeared an error posting your collection to OSMBC TBC: ' . $message;
        $mail->send();
    }
}
