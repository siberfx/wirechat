<?php

namespace Namu\WireChat\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;
use Namu\WireChat\Enums\ConversationType;
use Namu\WireChat\Models\Conversation;
use Namu\WireChat\Models\Message;

/**
 * Trait Chatable
 *
 * This trait defines the behavior for models that can participate in conversations within the WireChat system.
 * It provides methods to establish relationships with conversations, define cover images for avatars,
 * and specify the route for redirecting to the user's profile page.
 *
 * @package Namu\WireChat\Traits
 */
trait Chatable
{
    /**
     * Establishes a relationship between the user and conversations.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function conversations()
    {
        return $this->belongsToMany(Conversation::class, config('wirechat.participants_table','wirechat_participants'), 'user_id', 'conversation_id')
                    ->withPivot('conversation_id'); // Load the conversation_id from the pivot table
                  //  ->wherePivot('deleted_at', null); // Assuming soft deletes on participants
                   // ->whereNotDeleted(); // Apply your custom scope
    }


    /**
     * Creates a private conversation with another user and adds participants
     *
     * @param Model $user The user to create a conversation with
     * @param string|null $message The initial message (optional)
     * @return Conversation|null
     */
    public function createConversationWith(Model $user, ?string $message = null)
    {
        $userId = $user->id;
        $authenticatedUserId = $this->id;

        # Check if a private conversation already exists with these two participants
        $existingConversation = Conversation::where('type',ConversationType::PRIVATE)
        ->whereHas('participants', function ($query) use ($authenticatedUserId, $userId) {
            $query->select('conversation_id')
                  ->whereIn('user_id', [$authenticatedUserId, $userId])
                  ->groupBy('conversation_id')
                  ->havingRaw('COUNT(DISTINCT user_id) = 2');
        })
        ->first();    


        # If the conversation does not exist, create a new one
        if (!$existingConversation) {
            $existingConversation = Conversation::create([
                'type' => ConversationType::PRIVATE,
                'user_id' => $authenticatedUserId, // Assuming the authenticated user is the creator
            ]);

            # Add participants
            $existingConversation->participants()->createMany([
                ['user_id' => $authenticatedUserId],
                ['user_id' => $userId],
            ]);
        }

        # Create the initial message if provided
        if (!empty($message) && $existingConversation != null) {
            $createdMessage = Message::create([
                'user_id' => $authenticatedUserId,
                'conversation_id' => $existingConversation->id,
                'body' => $message
            ]);
        }

        return $existingConversation;
    }


    /**
     * Creates a conversation if one doesnt not already exists
     * And sends the attached message 
     * @return Message|null
     */

    function sendMessageTo(Model $user, string $message)
    {

        //Create or get converstion with user 
        $conversation = $this->createConversationWith($user);

        if ($conversation != null) {
            //create message
            $createdMessage = Message::create([
                'conversation_id' => $conversation->id,
                'user_id' => $this->id,
                'body' => $message
            ]);
            // dd($createdMessage);

            /** 
             * update conversation :we use this in to show the conversation
             *  with the latest message at the top of the chatlist  */
            $conversation->updated_at = now();
            $conversation->save();

            return $createdMessage;
        }

        //make sure user belong to conversation

    }


    /**
     * Returns the URL for the cover image to be used as an avatar.
     *
     * @return string|null
     */
    public function wireChatCoverUrl(): ?string
    {
        return null;
    }

    /**
     * Returns the URL for the user's profile page.
     *
     * @return string|null
     */
    public function wireChatProfileUrl(): ?string
    {
        return null;
    }

    /**
     * Returns the display name for the user.
     *
     * @return string|null
     */
    public function wireChatDisplayName(): ?string
    {
        return $this->name ?? 'user';
    }


    /**
     * Get unread messages count.
     *
     * @param Conversation|null $conversation
     * @return int
     */
    public function getUnReadCount(Conversation $conversation = null): int
    {
        // $query = $this->hasMany(Message::class, 'receiver_id')->where('read_at', null);

        // if ($conversation) {
        //     $query->where('conversation_id', $conversation->id);
        // }

        // return $query->count();

        return 0;
    }


    /**
     * Check if the user belongs to a conversation.
     */
    public function belongsToConversation(Conversation $conversation): bool
    {

        return $conversation->participants()
        ->where('user_id', auth()->id())
        ->exists();
    }
    public function deleteConversation(Conversation $conversation)
    {

        $userId = $this->id;

        //Stop if user does not belong to conversation
        if (! $this->belongsToConversation($conversation)) {
            return null;
        }

        // Update the messages based on the current user
        $conversation->messages()->each(function ($message) use ($userId) {
            if ($message->sender_id === $userId) {
                $message->update(['sender_deleted_at' => now()]);
            } elseif ($message->receiver_id === $userId) {
                $message->update(['receiver_deleted_at' => now()]);
            }
        });

        // Delete the conversation and messages if all messages from the other user are also deleted
        if ($conversation->messages()
            ->where(function ($query) use ($userId) {
                $query->where('sender_id', $userId)
                    ->orWhere('receiver_id', $userId);
            })
            ->where(function ($query) {
                $query->whereNull('sender_deleted_at')
                    ->orWhereNull('receiver_deleted_at');
            })
            ->doesntExist()
        ) {

            // $conversation->messages()->delete();
            $conversation->forceDelete();
        }
    }


    /**
     * Check if the user has a private conversation with another user.
     *
     * @param Model $user
     * @return bool
     */
    public function hasConversationWith(Model $user): bool
    {
        $authenticatedUserId = $this->id;
        $userId = $user->id;

        return Conversation::where('type', 'private')
            ->whereHas('participants', function ($query) use ($authenticatedUserId, $userId) {
                $query->select('conversation_id')
                    ->whereIn('user_id', [$authenticatedUserId, $userId])
                    ->groupBy('conversation_id')
                    ->havingRaw('COUNT(DISTINCT user_id) = 2');
            })
            ->exists();
    }






    /**
     * Retrieve the searchable fields defined in configuration
     * and check if they exist in the database table schema.
     *
     * @return array|null The array of searchable fields or null if none found.
     */
    public function getWireSearchableFields(): ?array
    {
        // Define the fields specified as searchable in the configuration
        $fieldsToCheck = config('wirechat.user_searchable_fields');

        // Get the table name associated with the model
        $tableName = $this->getTable();

        // Get the list of columns in the database table
        $tableColumns = Schema::getColumnListing($tableName);

        // Filter the fields to include only those that exist in the table schema
        $searchableFields = array_intersect($fieldsToCheck, $tableColumns);

        return $searchableFields ?: null;
    }
}
