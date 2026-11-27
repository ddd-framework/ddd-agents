import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, test, vi } from "vitest";
import { readAgentVoiceSamplePlayerState, resetAgentVoiceSamplePlayer } from "../agent-voice-sample-player";
import { AGENT_VOICE_SAMPLE_ERROR_TEXTS, AgentVoiceSampleButton, useAgentVoiceSamplePlayer } from "./AgentVoiceSampleButton";
// The ▶ of a voice: its label names the voice and says play or stop, a press plays and a second one stops, a press
// never reaches a surrounding select item, every button of the page shares one player, the reasons a sample did not
// play reach the application's `onError` as translated texts, and the sample stops when the last button leaves.
beforeEach(() => {
    vi.spyOn(URL, "createObjectURL").mockImplementation(() => "blob:sample");
    vi.spyOn(URL, "revokeObjectURL").mockImplementation(() => { });
    vi.spyOn(HTMLMediaElement.prototype, "play").mockImplementation(() => Promise.resolve());
    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(function () {
        this.dispatchEvent(new Event("pause"));
    });
});
afterEach(() => {
    cleanup();
    resetAgentVoiceSamplePlayer();
    vi.restoreAllMocks();
});
const Picker = ({ fetchSample, onError, onItemPress, }) => {
    const player = useAgentVoiceSamplePlayer({ fetchSample, onError });
    return (
    // biome-ignore lint/a11y/noStaticElementInteractions: stands in for a select item that chooses on press
    <div data-testid="item" onPointerUp={onItemPress} onClick={onItemPress} onKeyDown={onItemPress}>
      <AgentVoiceSampleButton voiceId="marin" languageCode="de" voiceName="Marin" player={player}/>
      <AgentVoiceSampleButton voiceId="cedar" languageCode="de" voiceName="Cedar" player={player}/>
    </div>);
};
describe("AgentVoiceSampleButton", () => {
    test("plays on a press, stops on the next, and its label follows", async () => {
        const fetchSample = vi.fn(async () => new Blob(["RIFF"]));
        render(<Picker fetchSample={fetchSample}/>);
        const marinButton = screen.getByRole("button", { name: "Play the sample of Marin" });
        await act(async () => {
            fireEvent.click(marinButton);
        });
        expect(fetchSample).toHaveBeenCalledWith("marin", "de");
        expect(screen.getByRole("button", { name: "Stop the sample of Marin" })).toHaveAttribute("aria-pressed", "true");
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Stop the sample of Marin" }));
        });
        expect(screen.getByRole("button", { name: "Play the sample of Marin" })).toHaveAttribute("aria-pressed", "false");
    });
    test("starting another voice stops the playing one: one player for the page", async () => {
        render(<Picker fetchSample={async () => new Blob(["RIFF"])}/>);
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Play the sample of Marin" }));
        });
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Play the sample of Cedar" }));
        });
        expect(screen.getByRole("button", { name: "Play the sample of Marin" })).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "Stop the sample of Cedar" })).toBeInTheDocument();
    });
    test("a press never reaches the surrounding select item", async () => {
        const onItemPress = vi.fn();
        render(<Picker fetchSample={async () => new Blob(["RIFF"])} onItemPress={onItemPress}/>);
        const marinButton = screen.getByRole("button", { name: "Play the sample of Marin" });
        await act(async () => {
            fireEvent.pointerDown(marinButton);
            fireEvent.pointerUp(marinButton);
            fireEvent.click(marinButton);
            fireEvent.keyDown(marinButton, { key: "Enter" });
        });
        expect(onItemPress).not.toHaveBeenCalled();
    });
    test("a sample that does not exist yet reaches onError as its text", async () => {
        const onError = vi.fn();
        render(<Picker fetchSample={() => Promise.reject({ status: 404 })} onError={onError}/>);
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Play the sample of Marin" }));
        });
        expect(onError).toHaveBeenCalledWith(AGENT_VOICE_SAMPLE_ERROR_TEXTS.notFound, "notFound");
        expect(AGENT_VOICE_SAMPLE_ERROR_TEXTS.notFound).toBe("There is no sample of this voice yet.");
    });
    test("the sample stops when the last button leaves the page", async () => {
        const { unmount } = render(<Picker fetchSample={async () => new Blob(["RIFF"])}/>);
        await act(async () => {
            fireEvent.click(screen.getByRole("button", { name: "Play the sample of Marin" }));
        });
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBe("marin/de");
        unmount();
        expect(readAgentVoiceSamplePlayerState().playingSampleKey).toBeNull();
    });
});
