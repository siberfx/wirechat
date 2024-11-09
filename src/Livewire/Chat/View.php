<?php

namespace Namu\WireChat\Livewire\Chat;

use Livewire\Attributes\Layout;

use Livewire\Attributes\Title;
use Livewire\Component;
use Namu\WireChat\Models\Conversation;
use Namu\WireChat\Models\Scopes\WithoutClearedScope;
use Namu\WireChat\Models\Scopes\WithoutDeletedScope;

class View extends Component{

  public $conversation_id;

  public $conversation;



  function mount()  {



  //  dd(str()->uuid());

    ///make sure user is authenticated
    abort_unless(auth()->check(),401);
    
    //We remove deleted conversation incase the user decides to visit the delted conversation 
    $this->conversation= Conversation::with('participants.participantable')->withoutGlobalScopes([WithoutDeletedScope::class])->where('id',$this->conversation_id)->firstOrFail();
    

    // dd( $this->conversation->unique_id);
    //dd($this->conversation);Fw2GKQ5xMvCHP80Ikyzjae
   //dd( $this->conversation->hasBeenDeletedBy(auth()->user()));


    ///check if auth belongs to conversaiton
    // Check if the user belongs to the conversation

    abort_unless(auth()->user()->belongsToConversation($this->conversation), 403);

    //Mark as read 
    $this->conversation->markAsRead();

    
  }


  #[Layout('wirechat::layouts.app')] 
  #[Title('Chats')] 
  public function render()
  {

      return <<<'BLADE'
              <div class="w-full h-[calc(100vh_-_0.0rem)]  flex rounded-lg" >
                  <div class=" hidden md:flex bg-inherit  dark:bg-inherit  relative w-full h-full md:w-[360px] lg:w-[400px] xl:w-[500px]  shrink-0 overflow-y-auto  ">

                      @livewire('chats')
                  </div>
                  
                  <main  class="  grid  w-full  grow  h-full relative overflow-y-auto"  style="contain:content">

                    
                    <livewire:chat lazy conversation="{{$conversation->id}}"/>

                  </main>


              </div>
      BLADE;
  }



}