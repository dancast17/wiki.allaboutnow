<?php

namespace BookStack\Uploads\Controllers;

use BookStack\Exceptions\ImageUploadException;
use BookStack\Http\Controller;
use BookStack\Uploads\ImageRepo;
use BookStack\Uploads\ImageResizer;
use BookStack\Util\OutOfMemoryHandler;
use Exception;
use Illuminate\Http\Request;

class DrawioImageController extends Controller
{
    public function __construct(
        protected ImageRepo $imageRepo
    ) {
    }

    /**
     * Get a list of gallery images, in a list.
     * Can be paged and filtered by entity.
     */
    public function list(Request $request, ImageResizer $resizer)
    {
        $page = $request->get('page', 1);
        $searchTerm = $request->get('search', null);
        $uploadedToFilter = $request->get('uploaded_to', null);
        $parentTypeFilter = $request->get('filter_type', null);

        $imgData = $this->imageRepo->getEntityFiltered('drawio', $parentTypeFilter, $page, 24, $uploadedToFilter, $searchTerm);
        $viewData = [
            'warning' => '',
            'images'  => $imgData['images'],
            'hasMore' => $imgData['has_more'],
        ];

        new OutOfMemoryHandler(function () use ($viewData) {
            $viewData['warning'] = trans('errors.image_gallery_thumbnail_memory_limit');
            return response()->view('pages.parts.image-manager-list', $viewData, 200);
        });

        $resizer->loadGalleryThumbnailsForMany($imgData['images']);

        return view('pages.parts.image-manager-list', $viewData);
    }

    /**
     * Store a new gallery image in the system.
     *
     * @throws Exception
     */
    public function create(Request $request)
    {
        $this->validate($request, [
            'image'       => ['required', 'string'],
            'uploaded_to' => ['required', 'integer'],
        ]);

        $this->checkPermission('image-create-all');
        $imageBase64Data = $request->get('image');

        try {
            $uploadedTo = $request->get('uploaded_to', 0);
            $image = $this->imageRepo->saveDrawing($imageBase64Data, $uploadedTo);
        } catch (ImageUploadException $e) {
            return response($e->getMessage(), 500);
        }

        return response()->json($image);
    }

    /**
     * Get the content of an image based64 encoded.
     */
    public function getAsBase64($id)
    {
        try {
            $image = $this->imageRepo->getById($id);
        } catch (Exception $exception) {
            return $this->jsonError(trans('errors.drawing_data_not_found'), 404);
        }

        if ($image->type !== 'drawio' || !userCan('page-view', $image->getPage())) {
            return $this->jsonError(trans('errors.drawing_data_not_found'), 404);
        }

        $imageData = $this->imageRepo->getImageData($image);
        if (is_null($imageData)) {
            return $this->jsonError(trans('errors.drawing_data_not_found'), 404);
        }

        return response()->json([
            'content' => base64_encode($imageData),
        ]);
    }
}
