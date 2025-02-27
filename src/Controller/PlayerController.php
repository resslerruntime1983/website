<?php

namespace App\Controller;

use App\Crawling\Shownotes\ShownotesParserFactory;
use App\Entity\Episode;
use App\Entity\EpisodeChapter;
use App\Entity\EpisodeChapterDraft;
use App\Repository\EpisodeChapterDraftRepository;
use App\Repository\EpisodeChapterRepository;
use App\Repository\EpisodeRepository;
use App\Utilities;
use Benlipp\SrtParser\Parser as SrtParser;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;

class PlayerController extends AbstractController
{
    public function __construct(
        private EpisodeRepository $episodeRepository,
        private EpisodeChapterRepository $episodeChapterRepository,
        private EpisodeChapterDraftRepository $episodeChapterDraftRepository,
        private ShownotesParserFactory $shownotesParserFactory,
    ) {}

    #[Route('/listen', name: 'player_latest')]
    public function latest(): Response
    {
        $episode = $this->episodeRepository->findLastPublishedEpisode();

        return $this->redirectToRoute('player', ['episode' => $episode->getCode()], Response::HTTP_TEMPORARY_REDIRECT);
    }

    /**
     * @ParamConverter("episode", class="App\Entity\Episode", options={"mapping": {"episode": "code"}})
     */
    #[Route('/listen/{episode}', name: 'player')]
    public function player(Request $request, Episode $episode): Response
    {
        $timestamp = Utilities::parsePrettyTimestamp($request->query->get('t', 0));
        $transcriptTimestamp = Utilities::parsePrettyTimestamp($request->query->get('transcript', 0));

        if ($transcriptTimestamp > 0) {
            $timestamp = $transcriptTimestamp;
        }

        if ($episode->hasTranscript()) {
            $transcriptLines = (new SrtParser())->loadString(file_get_contents($episode->getTranscriptPath()))->parse();
        }

        $chapters = array_merge(
            $this->episodeChapterRepository->findByEpisode($episode),
            $this->episodeChapterDraftRepository->findNewSuggestionsByEpisode($episode, $this->getUser() ?? null)
        );

        uasort($chapters, function ($a, $b) {
            /** @var EpisodeChapter|EpisodeChapterDraft $a */
            /** @var EpisodeChapter|EpisodeChapterDraft $b */
            return $a->getStartsAt() - $b->getStartsAt();
        });

        if ($episode->hasShownotes()) {
            $shownotes = $this->shownotesParserFactory->create($episode);
        }

        return $this->render('player/episode.html.twig', [
            'timestamp' => $timestamp,
            'transcriptTimestamp' => $transcriptTimestamp,

            'episode' => $episode,
            'chapters' => $chapters,
            'shownotes' => $shownotes ?? null,
            'transcriptLines' => $transcriptLines ?? [],
        ]);
    }

    /**
     * @ParamConverter("episode", class="App\Entity\Episode", options={"mapping": {"episode": "code"}})
     */
    #[Route('/listen/{episode}/audio', name: 'player_audio')]
    public function audio(Episode $episode): Response
    {
        if (null === $recordingUri = $episode->getRecordingUri()) {
            throw new NotFoundHttpException();
        }

        return new RedirectResponse($recordingUri, Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @ParamConverter("episode", class="App\Entity\Episode", options={"mapping": {"episode": "code"}})
     */
    #[Route('/listen/{episode}/chat', name: 'player_chat_archive')]
    public function chatArchive(Episode $episode): Response
    {
        if (!$episode->hasChatArchive()) {
            throw new NotFoundHttpException();
        }

        return new BinaryFileResponse($episode->getChatArchivePath());
    }
}
