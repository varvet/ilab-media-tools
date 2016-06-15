<?php

if (!defined('ABSPATH')) { header('Location: /'); die; }

require_once(ILAB_VENDOR_DIR.'/autoload.php');
require_once(ILAB_CLASSES_DIR.'/ilab-media-tool-base.php');
require_once(ILAB_CLASSES_DIR.'/tasks/ilab-s3-import-process.php');


class ILabMediaS3Tool extends ILabMediaToolBase {

    private $key = null;
    private $secret = null;
    private $region = null;
    private $bucket = null;
    private $docCdn = null;
    private $cdn = null;

    private $settingsError = false;

    public function __construct($toolName, $toolInfo, $toolManager)
    {
        parent::__construct($toolName, $toolInfo, $toolManager);

        new ILABS3ImportProcess();

        $this->bucket=get_option('ilab-media-s3-bucket', getenv('ILAB_AWS_S3_BUCKET'));
        $this->key = get_option('ilab-media-s3-access-key', getenv('ILAB_AWS_S3_ACCESS_KEY'));
        $this->secret = get_option('ilab-media-s3-secret', getenv('ILAB_AWS_S3_ACCESS_SECRET'));
        $this->region=get_option('ilab-media-s3-region', getenv('ILAB_AWS_S3_REGION'));

        $this->cdn = get_option('ilab-media-s3-cdn-base', getenv('ILAB_AWS_S3_CDN_BASE'));
        if (!$this->cdn && $this->bucket && $this->region)  {
            $this->cdn = "https://s3-{$this->region}.amazonaws.com/{$this->bucket}";
        }

        $this->docCdn = get_option('ilab-doc-s3-cdn-base', $this->cdn);

        $this->settingsError = get_option('ilab-s3-settings-error', false);

        if ($this->haveSettingsChanged()) {
            $this->settingsChanged();
        }

        if ($this->settingsError) {
            $this->displayAdminNotice('error', 'Your AWS S3 settings are incorrect or the bucket does not exist.  Please verify your settings and update them.');
        }

        if (is_admin()) {
            add_action('wp_ajax_ilab_s3_import_media', [$this,'importMedia']);
            add_action('wp_ajax_ilab_s3_import_progress', [$this,'importProgress']);
        }
    }

    public function registerMenu($top_menu_slug) {
        parent::registerMenu($top_menu_slug); // TODO: Change the autogenerated stub

        if (!$this->settingsError)
            add_submenu_page( $top_menu_slug, 'S3 Importer', 'S3 Importer', 'manage_options', 'media-tools-s3-importer', [$this,'renderImporter']);
    }

    public function s3enabled() {
        if (!($this->key && $this->secret && $this->bucket && $this->region && $this->cdn))
        {
            $this->displayAdminNotice('error',"To start using S3, you will need to <a href='admin.php?page={$this->options_page}'>supply your AWS credentials.</a>.");
            return false;
        }

        if ($this->settingsError)
            return false;

        return true;
    }

    public function enabled()
    {
        $enabled = $this->s3enabled();

        if (!$enabled)
            return false;

        return parent::enabled();
    }

    public function setup()
    {
        parent::setup();

        if ($this->enabled())
        {
            add_filter('wp_update_attachment_metadata', [$this, 'updateAttachmentMetadata'], 1000, 2);
            add_filter('delete_attachment', [$this, 'deleteAttachment'], 1000);
            add_filter('wp_handle_upload', [$this, 'handleUpload'], 10000);
        }

        if ($this->cdn)
            add_filter('wp_get_attachment_url', [$this, 'getAttachmentURL'], 1000, 2 );
    }


    private function s3Client($insure_bucket=false)
    {
        if (!$this->s3enabled())
            return null;

        $s3=new Aws\S3\S3Client([
                                    'version' => 'latest',
                                    'region'  => $this->region,
                                    'credentials' => [
                                        'key'    => $this->key,
                                        'secret' => $this->secret
                                    ]
                                ]);

        if ($insure_bucket && (!$s3->doesBucketExist($this->bucket)))
            return null;

        return $s3;
    }

    /**
     * Filter for when attachments are updated
     *
     * @param $data
     * @param $id
     * @return mixed
     */
    public function updateAttachmentMetadata($data,$id)
    {
        if (!$data)
            return $data;

        $s3=$this->s3Client(true);
        if ($s3)
        {
            $upload_info=wp_upload_dir();
            $upload_path=$upload_info['basedir'];
            $path_base=pathinfo($data['file'])['dirname'];

            $data=$this->process_file($s3,$upload_path,$data['file'],$data);

            foreach($data['sizes'] as $key => $size)
            {
                if (!is_array($size))
                    continue;

                $file=$path_base.'/'.$size['file'];
                $data['sizes'][$key]=$this->process_file($s3,$upload_path,$file,$size);
            }
        }

        return $data;
    }

    public function handleUpload($upload, $context='upload') {
        if (!$this->docCdn)
            return $upload;

        if (!isset($upload['file']))
            return $upload;

        if (file_is_displayable_image($upload['file']))
            return $upload;

        $s3=$this->s3Client(true);
        if ($s3)
        {
            $pi = pathinfo($upload['file']);

            $upload_info=wp_upload_dir();
            $upload_path=$upload_info['basedir'];

            $file = trim(str_replace($upload_path,'',$pi['dirname']),'/').'/'.$pi['basename'];

            $upload = $this->process_file($s3, $upload_path, $file, $upload);
            if (isset($upload['s3'])) {
                $upload['url'] = trim($this->docCdn, '/').'/'.$file;
            }
        }

        return $upload;
    }

    private function process_file($s3,$upload_path,$filename,$data)
    {
        if (!file_exists($upload_path.'/'.$filename))
            return $data;

        if (isset($data['s3']))
        {
            $key = $data['s3']['key'];

            if ($key == $filename)
                return $data;

            $this->delete_file($s3,$key);
        }

        $file=fopen($upload_path.'/'.$filename,'r');
        try
        {
            $s3->upload($this->bucket,$filename,$file,'public-read');
            $data['s3']=[
              'bucket'=>$this->bucket,
              'key'=>$filename
            ];
        }
        catch (\Aws\Exception\AwsException $ex)
        {
            error_log($ex->getMessage());
        }

        return $data;
    }

    /**
     * Filters for when attachments are deleted
     * @param $id
     * @return mixed
     */
    public function deleteAttachment($id)
    {
        $s3=$this->s3Client(true);
        if ($s3)
        {
            $data=wp_get_attachment_metadata($id);

            $path_base=pathinfo($data['file'])['dirname'];

            $this->delete_file($s3,$data['file']);

            if (isset($data['sizes'])) {
                foreach($data['sizes'] as $key => $size) {
                    $file=$path_base.'/'.$size['file'];
                    try {
                        $this->delete_file($s3,$file);
                    } catch (\Exception $ex) {
                        error_log($ex->getMessage());
                    }
                }
            }
        }

        return $id;
    }

    private function delete_file($s3,$file)
    {
        try
        {
            if ($s3->doesObjectExist($this->bucket,$file))
            {
                $s3->deleteObject(array(
                                      'Bucket' => $this->bucket,
                                      'Key'    => $file
                                  ));
            }
        }
        catch (\Aws\Exception\AwsException $ex)
        {
            error_log($ex->getMessage());
        }
    }

    public function getAttachmentURL($url, $post_id)
    {
        $meta=wp_get_attachment_metadata($post_id);
        if (isset($meta['s3'])) {
            return $this->cdn.'/'.$meta['file'];
        }
        else if (isset($meta['amazonS3_info'])) {
            return $this->cdn.'/'.$meta['file'];
        }
        else if (!$meta) {
            $post = \WP_Post::get_instance($post_id);
            if (strpos($post->guid, $this->docCdn) === 0)
                return $post->guid;
        }

        return $url;
    }

    public function settingsChanged() {
        delete_option('ilab-s3-settings-error');
        $this->settingsError = false;

        if ($this->s3enabled()) {
            $s3 = $this->s3Client(true);
            if ($s3 == null) {
                $this->settingsError = true;
                update_option('ilab-s3-settings-error', true);
            }
        }
    }

    public function renderImporter() {

        $status = get_option('ilab_s3_import_status', false);
        $total = get_option('ilab_s3_import_total_count', 0);
        $current = get_option('ilab_s3_import_current', 1);

        if ($total == 0) {
            $attachments = get_posts([
                                         'post_type'=> 'attachment',
                                         'posts_per_page' => -1
                                     ]);

            $total = count($attachments);
        }

        $progress = 0;

        if ($total > 0) {
            $progress = ($current / $total) * 100;
        }

        echo render_view('s3/ilab-s3-importer.php',[
            'status' => ($status) ? 'running' : 'idle',
            'total' => $total,
            'progress' => $progress,
            'current' => $current
        ]);
    }

    public function importProgress() {
        $status = get_option('ilab_s3_import_status', false);
        $total = get_option('ilab_s3_import_total_count', 0);
        $current = get_option('ilab_s3_import_current', 0);

        header('Content-type: application/json');
        echo json_encode([
                             'status' => ($status) ? 'running' : 'idle',
                             'total' => (int)$total,
                             'current' => (int)$current
                         ]);
        die;
    }

    public function importMedia() {

        $attachments = get_posts([
                                     'post_type'=> 'attachment',
                                     'posts_per_page' => -1
                                 ]);


        if (count($attachments)>0) {
            update_option('ilab_s3_import_status', true);
            update_option('ilab_s3_import_total_count', count($attachments));
            update_option('ilab_s3_import_current', 1);

            $process = new ILABS3ImportProcess();

            for($i = 0; $i<count($attachments); $i++) {
                $process->push_to_queue(['index' => $i, 'post' => $attachments[$i]->ID]);
            }

            $process->save();
            $process->dispatch();
        } else {
            update_option('ilab_s3_import_status', false);
        }

        header('Content-type: application/json');
        echo '{"status":"running"}';
        die;
    }
}