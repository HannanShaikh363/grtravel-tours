<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Wishlist;
use App\Models\CancellationPolicies;
use App\Models\Rate;
use App\Models\TourRate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class WishlistController extends Controller
{
    public function index()
    {
        // Fetch the wishlists for the authenticated user
        $wishlists = Wishlist::where('user_id', Auth::id())->get();

        // Eager load either rate or tourRate based on the type
        $wishlists->each(function ($wishlist) {
            if ($wishlist->type == 'tour') {
                // For tour type, load the related TourRate
                $wishlist->tourRate = TourRate::find($wishlist->rate_id);
            } else {
                // For regular rates, load the related Rate
                $wishlist->rate = Rate::find($wishlist->rate_id);
            }
        });

        // Return the view with the wishlists
        return view('wishlist.index', [
            'wishlists' => $wishlists,
            'cancellationPolicy' => CancellationPolicies::where('active', 1)->where('type', 'transfer')->first()
        ]);
    }


    public function add(Request $request)
    {
        // Validate the input
        $validated = $request->validate([
            'rateId' => 'required|integer', // Ensure rateId is an integer
            'type' => 'required|in:transfer,tour', // Ensure type is either 'rate' or 'tour'
        ]);

        // Handle the logic based on the type
        if ($request->type == 'tour') {
            // Ensure the tour rate exists
            Log::info($request->rateId);
            $tourRate = TourRate::find($request->rateId);
            if (!$tourRate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tour rate does not exist',
                ]);
            }

            // Check if the rate is already in the wishlist
            $wishlist = Wishlist::where([
                'user_id' => Auth::id(),
                'rate_id' => $request->rateId,
                'type' => 'tour',
            ])->first();

            if ($wishlist) {
                // If the record exists, delete it
                $wishlist->delete();
                return response()->json([
                    'success' => true,
                    'isWishlisted' => false,
                    'message' => 'Tour rate removed from wishlist',
                ]);
            } else {
                // If the record does not exist, create it
                Wishlist::create([
                    'user_id' => Auth::id(),
                    'rate_id' => $request->rateId, // Store the rate_id (tour rate)
                    'type' => 'tour',
                ]);
                return response()->json([
                    'success' => true,
                    'isWishlisted' => true,
                    'message' => 'Tour rate added to wishlist',
                ]);
            }
        } else {
            // Ensure the rate exists
            $rate = Rate::find($request->rateId);
            if (!$rate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transfer does not exist',
                ]);
            }

            // Check if the rate is already in the wishlist
            $wishlist = Wishlist::where([
                'user_id' => Auth::id(),
                'rate_id' => $request->rateId,
                'type' => 'transfer',
            ])->first();

            if ($wishlist) {
                // If the record exists, delete it
                $wishlist->delete();
                return response()->json([
                    'success' => true,
                    'isWishlisted' => false,
                    'message' => 'Transfer removed from wishlist',
                ]);
            } else {
                // If the record does not exist, create it
                Wishlist::create([
                    'user_id' => Auth::id(),
                    'rate_id' => $request->rateId, // Store the rate_id (regular rate)
                    'type' => 'rate',
                ]);
                return response()->json([
                    'success' => true,
                    'isWishlisted' => true,
                    'message' => 'Transfer added to wishlist',
                ]);
            }
        }
    }



    public function remove($id)
    {
        $wishlist = Wishlist::where('user_id', Auth::id())->where('id', $id)->firstOrFail();
        $wishlist->delete();

        return response()->json(['message' => 'Transfer removed from wishlist']);
    }
}
