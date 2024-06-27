<?php

namespace Namu\WireChat\Livewire\Chat;

use Livewire\Component;
//use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Namu\WireChat\Models\Conversation;
use Namu\WireChat\Models\Message;

use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithPagination;
use Namu\WireChat\Models\Attachment;

use function Laravel\Prompts\alert;

class ChatBox extends Component
{

    use WithFileUploads;
    use WithPagination;

    public Conversation $conversation;

    public $receiver;
    public $body;

    public $loadedMessages;
    public int $paginate_var = 10;
    public bool $canLoadMore;


    public $media = [];


    public $files = [];



    //Theme 
    public string $authMessageBodyColor;
    
    public $replyMessage=null;

    /** 
     * Todo: Authorize the property
     * Todo: or lock it 
     * todo:Check if user can reply to this message 
     * Set replyMessage as Message Model
     *  */
     public function setReply(Message $message)  {
         #check if user belongs to message
         abort_unless($message->sender_id == auth()->id() || $message->receiver_id == auth()->id(),403);

        //Set owner as Id we are replying to 
        $this->replyMessage= $message;

        #dispatch event to focus input field 
        $this->dispatch('focus-input-field');

    }

    public function removeReply()  {

        $this->replyMessage= null;
    }

    /**
     * livewire method
     ** This is avoid replacing temporary files on add more files
     * We override the function in WithFileUploads Trait
     */
     function _finishUpload($name, $tmpPath, $isMultiple)
    {
        $this->cleanupOldUploads();


        $files = collect($tmpPath)->map(function ($i) {
            return TemporaryUploadedFile::createFromLivewire($i);
        })->toArray();
        $this->dispatch('upload:finished', name: $name, tmpFilenames: collect($files)->map->getFilename()->toArray())->self();

        // If the property is an array, APPEND the upload to the array.
        $currentValue = $this->getPropertyValue($name);

        if (is_array($currentValue)) {
            $files = array_merge($currentValue, $files);
        } else {
            $files = $files[0];
        }

        app('livewire')->updateProperty($this, $name, $files);
    }


    function listenBroadcastedMessage($event)
    {

        // dd('reached');
        $this->dispatch('scroll-bottom');
        $newMessage = Message::find($event['message_id']);



        #push message
        $this->loadedMessages->push($newMessage);

        #mark as read
        $newMessage->read_at = now();
        $newMessage->save();
    }


    function sendMessage()
    {

        abort_unless(auth()->check(),401);

        /* If media is empty then conitnue to validate body , since media can be submited without body */
        // Combine media and files arrays
        $attachments = array_merge($this->media, $this->files);

        // If combined files array is empty, continue to validate body
        if (empty($attachments)) {
            $this->validate(['body' => 'required|string']);
        }

        if (count($attachments)!=0 ) {
          //  dd("alert('message')");





            //Validation 
            //Files 
           try {
           // $this->js("alert('message')");

            $this->validate([ 
                'files' => 'min:3|max:288|nullable',
                'files.*' => 'file|mimes:pdf,zip,docx',
                'media' => 'file|min:3|nullable',

            ]);

           } catch (\Illuminate\Validation\ValidationException $th) {

            dd($th->errors()['files']);
            return $this->dispatch('notify',type:'warning',message:'File limit exceeded , allowed ');
           // $this->dispatch('notify',"{type:'warning',message:'File limit exceeded , allowed '}");
           }


            //Combine media and files thne perform loop together

            $createdMessages = [];
            foreach ($attachments as $key => $attachment) {

                /**
                 * todo: Add url to table
                 */

                #save photo to disk 
                $path =  $attachment->store(config('wirechat.attachments.storage_folder','attachments'), config('wirechat.attachments.storage_disk'));

                #create attachment
                $createdAttachment = Attachment::create([
                    'file_path' => $path,
                    'file_name' => basename($path),
                    'original_name' => $attachment->getClientOriginalName(),
                    'mime_type' => $attachment->getMimeType(),
                    'url'=>url($path)
                ]);


                #create message
                $message = Message::create([
                    'reply_id'=>$this->replyMessage?->id,
                    'conversation_id' => $this->conversation->id,
                    'attachment_id' => $createdAttachment->id,
                    'sender_id' => auth()->id(),
                    'receiver_id' => $this->receiver->id,
                    // 'body'=>$this->body
                ]);

                #append message to createdMessages
                $createdMessages[] = $message;
                

                #update the conversation model - for sorting in chatlist
                $this->conversation->updated_at = now();
                $this->conversation->save();

                #dispatch event 'refresh ' to chatlist 
                $this->dispatch('refresh')->to(ChatList::class);
            }

            #push the message
            $this->loadedMessages = $this->loadedMessages->concat($createdMessages);

            #scroll to bottom
            $this->dispatch('scroll-bottom');
        }


        if ($this->body != null) {

            $createdMessage = Message::create([
                'reply_id'=>$this->replyMessage?->id,
                'conversation_id' => $this->conversation->id,
                'sender_id' => auth()->id(),
                'receiver_id' => $this->receiver->id,
                'body' => $this->body
            ]);



            $this->reset('body');

            #push the message
            $this->loadedMessages->push($createdMessage);


            #update the conversation model - for sorting in chatlist
            $this->conversation->updated_at = now();
            $this->conversation->save();

            #dispatch event 'refresh ' to chatlist 
            $this->dispatch('refresh')->to(ChatList::class);
        }
        $this->reset('media','files','body');

        #scroll to bottom
        $this->dispatch('scroll-bottom');

        #remove reply just incase it is present 
        $this->removeReply();
    }


    /** Send Like as  message */
    public function sendLike()
    {


        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'attachment_id' => null,
            'sender_id' => auth()->id(),
            'receiver_id' => $this->receiver->id,
            'body' => '❤️'
        ]);


        #update the conversation model - for sorting in chatlist
        $this->conversation->updated_at = now();
        $this->conversation->save();

         #push the message
         $this->loadedMessages->push($message);

        #dispatch event 'refresh ' to chatlist 
        $this->dispatch('refresh')->to(ChatList::class);





        #scroll to bottom
        $this->dispatch('scroll-bottom');
    }

    
   // #[On('loadMore')]
    function loadMore()
    {
        //dd('reached');

        #increment
        $this->paginate_var += 10;
        #call loadMessage
        $this->loadMessages();

        #dispatch event- update height
        $this->dispatch('update-height');
    }


    function loadMessages()
    {

        #get count
        $count = Message::where('conversation_id', $this->conversation->id)->count();

        #skip and query
        $this->loadedMessages = Message::where('conversation_id', $this->conversation->id)
            ->with('parent')
            ->skip($count - $this->paginate_var)
            ->take($this->paginate_var)
            ->get();

       // Calculate whether more messages can be loaded
        $this->canLoadMore = $count > count($this->loadedMessages);



        return $this->loadedMessages;
    }

    /* to generate color auth message background color */
   public function getAuthMessageBodyColor() : string {

      $color= config('wirechat.theme','blue');

      return 'bg-'.$color.'-500';

        
    }

    function mount()
    {

        abort_unless(auth()->check(),401);

         #check if user belongs to conversation
        $belongsToConversation = auth()->user()->conversations()
                    ->where('id', $this->conversation->id)
                    ->exists();
                    
        abort_unless($belongsToConversation, 403);



        $this->receiver = $this->conversation->getReceiver();

        $this->authMessageBodyColor = $this->getAuthMessageBodyColor();

        $this->loadMessages();
    }

    public function render()
    {
        return view('wirechat::livewire.chat.chat-box');
    }
}
