import { useEffect, useRef } from "react";
import { isAgentVoiceCallActive, useAgentVoiceCallValue, useIsAgentVoiceCallActive } from "../call/agent-voice-call-store";
import { turnOfAgentVoiceCall } from "../call/agent-voice-call-turn";
import { glowVoiceOfAgentVoiceCallTurn } from "./agent-voice-input-glow-voice";
import styles from "./AgentVoiceInputGlow.module.scss";
/** How far past the input the canvases reach: room for the loudest wave and its fade. Same as the canvases' inset in
 * the stylesheet. */
const CANVAS_PADDING_PX = 200;
/** The glow's reach past the edge at rest. */
const REST_REACH_PX = 9;
/** How much further the loudest syllable takes it. */
const LOUD_REACH_PX = 42;
/** How finely the glow's edge follows the wave (CSS px). */
const EDGE_STEP_PX = 4;
/** The burst when the field is clicked into: how long (s) and how much further it takes the glow. */
const BURST_SECONDS = 1;
const BURST_REACH_PX = 22;
/** The agent's speaking pace: one word every this many ms sets the pulse of its syllables. */
const AGENT_MS_PER_WORD = 330;
const OWNER_MS_PER_SYLLABLE = 260;
/**
 * The glow's shades, round the field: custom properties with their defaults. A property may be a relative colour a
 * canvas cannot read, so the page resolves it.
 */
const GLOW_COLOUR_PROPERTIES = [
    ["--agents-voice-glow-color-1", "#00495d"],
    ["--agents-voice-glow-color-2", "#29ecfa"],
    ["--agents-voice-glow-color-3", "#e0c9fd"],
    ["--agents-voice-glow-color-4", "#12ffff"],
    ["--agents-voice-glow-color-1", "#00495d"],
];
/**
 * The glow itself: a soft light round the field in the agent's colours, flowing round it, that swells into a waveform
 * as the agent talks (the waves running out from the top middle in time with its syllables), the owner's smaller, from
 * the bottom edge; a slow breath while the agent thinks; a short even glow at rest. Clicking into the field bursts it
 * out. Two canvases behind the field: the glow close in, and a wider, fainter copy.
 */
const AgentVoiceInputGlowCanvases = (props) => {
    const { testId } = props;
    const voice = useAgentVoiceCallValue((call) => isAgentVoiceCallActive(call.phase) ? glowVoiceOfAgentVoiceCallTurn(turnOfAgentVoiceCall(call)) : "quiet");
    const boxRef = useRef(null);
    const edgeCanvasRef = useRef(null);
    const haloCanvasRef = useRef(null);
    // Read by the drawing loop each frame, so a change of voice does not restart it.
    const voiceRef = useRef(voice);
    voiceRef.current = voice;
    const burstStartedAtSecondsRef = useRef(Number.NEGATIVE_INFINITY);
    useEffect(() => {
        const box = boxRef.current;
        const edgeCanvas = edgeCanvasRef.current;
        const haloCanvas = haloCanvasRef.current;
        const edgeContext = edgeCanvas?.getContext("2d");
        const haloContext = haloCanvas?.getContext("2d");
        const maskCanvas = document.createElement("canvas");
        const maskContext = maskCanvas.getContext("2d");
        if (!box || !edgeCanvas || !haloCanvas || !edgeContext || !haloContext || !maskContext)
            return;
        const prefersReducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
        const colourProbe = document.createElement("span");
        box.appendChild(colourProbe);
        const glowColours = GLOW_COLOUR_PROPERTIES.map(([property, defaultColour]) => {
            colourProbe.style.color = `var(${property}, ${defaultColour})`;
            return getComputedStyle(colourProbe).color;
        });
        colourProbe.remove();
        // The field is the input the glow sits under: clicking into it bursts the glow out.
        const field = box.parentElement;
        const startBurst = () => {
            burstStartedAtSecondsRef.current = performance.now() / 1000;
        };
        field?.addEventListener("focusin", startBurst);
        let width = 0;
        let height = 0;
        let pixelRatio = 1;
        const resizeCanvases = () => {
            const boxRect = box.getBoundingClientRect();
            width = boxRect.width;
            height = boxRect.height;
            pixelRatio = Math.min(2, window.devicePixelRatio || 1);
            [edgeCanvas, haloCanvas, maskCanvas].forEach((canvas) => {
                canvas.width = Math.round((width + CANVAS_PADDING_PX * 2) * pixelRatio);
                canvas.height = Math.round((height + CANVAS_PADDING_PX * 2) * pixelRatio);
            });
        };
        let loudness = 0;
        // -1: the sound comes from the top (the agent, above); 1: from the bottom (the owner).
        let soundSide = -1;
        let colourTurn = 0;
        let lastFrameSeconds = 0;
        let animationFrameId = 0;
        const drawFrame = (frameMs) => {
            const frameSeconds = frameMs / 1000;
            const frameDeltaSeconds = lastFrameSeconds ? Math.min(0.1, frameSeconds - lastFrameSeconds) : 0.016;
            lastFrameSeconds = frameSeconds;
            const currentVoice = voiceRef.current;
            const syllable = (periodMs) => Math.abs(Math.sin((Math.PI * frameMs) / periodMs));
            const targetLoudness = currentVoice === "agent"
                ? (0.5 + 0.5 * syllable(AGENT_MS_PER_WORD)) * (0.85 + 0.15 * Math.sin(frameSeconds * 23.7))
                : currentVoice === "owner"
                    ? 0.42 * (0.55 + 0.45 * syllable(OWNER_MS_PER_SYLLABLE))
                    : currentVoice === "thinking"
                        ? 0.12 * (0.5 + 0.5 * Math.sin(frameSeconds * 2.4))
                        : 0;
            // Quick to rise, slower to fall, as a level meter.
            const loudnessTimeConstant = targetLoudness > loudness ? 0.05 : 0.2;
            loudness += (targetLoudness - loudness) * (1 - Math.exp(-frameDeltaSeconds / loudnessTimeConstant));
            soundSide += ((currentVoice === "owner" ? 1 : -1) - soundSide) * (1 - Math.exp(-frameDeltaSeconds / 0.35));
            // The colours flow round the field, faster as the agent talks.
            colourTurn += frameDeltaSeconds * (0.1 + 0.4 * loudness);
            const burstProgress = (frameSeconds - burstStartedAtSecondsRef.current) / BURST_SECONDS;
            const burst = burstProgress >= 0 && burstProgress < 1 ? Math.pow(1 - burstProgress, 1.4) * Math.min(1, burstProgress / 0.06) : 0;
            const cornerRadius = Math.min(24, height / 2);
            // How far the glow reaches at x, up from the top edge or down from the bottom: waves running out both ways from
            // the middle, tall there and low at the sides.
            const reachAt = (x, isUpward) => {
                const distanceFromMiddle = Math.abs(x - width / 2);
                const nearMiddle = 0.12 + 0.88 * Math.exp(-((distanceFromMiddle / (width * 0.3)) ** 2));
                const wave = Math.abs(0.6 * Math.sin(distanceFromMiddle * 0.01375 - frameSeconds * 3.2 + (isUpward ? 0 : 1.7)) +
                    0.4 * Math.sin(distanceFromMiddle * 0.0325 - frameSeconds * 5.1 + 1.3)) *
                    (0.65 + 0.35 * Math.sin(distanceFromMiddle * 0.00475 + frameSeconds * 1.05));
                const fromThisSide = isUpward ? (1 - soundSide) / 2 : (1 + soundSide) / 2;
                const loudReach = LOUD_REACH_PX * loudness * nearMiddle * wave * (0.25 + 0.75 * fromThisSide);
                const burstReach = BURST_REACH_PX * burst * (0.45 + 0.55 * Math.exp(-((distanceFromMiddle / (width * 0.6)) ** 2)));
                return REST_REACH_PX + loudReach + burstReach;
            };
            const canvasWidth = width + CANVAS_PADDING_PX * 2;
            const canvasHeight = height + CANVAS_PADDING_PX * 2;
            edgeContext.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
            edgeContext.globalCompositeOperation = "source-over";
            edgeContext.clearRect(0, 0, canvasWidth, canvasHeight);
            // One soft shape round the field, its top and bottom edges the waveform, filled with the glow's colours.
            const colourGradient = edgeContext.createConicGradient(colourTurn * Math.PI * 2, CANVAS_PADDING_PX + width / 2, CANVAS_PADDING_PX + height / 2);
            glowColours.forEach((colour, index) => colourGradient.addColorStop(index / (glowColours.length - 1), colour));
            edgeContext.fillStyle = colourGradient;
            const topEdgePoints = [];
            const bottomEdgePoints = [];
            for (let x = -CANVAS_PADDING_PX; x <= width + CANVAS_PADDING_PX; x += EDGE_STEP_PX) {
                let fieldTop = height / 2;
                let fieldBottom = height / 2;
                if (x >= 0 && x <= width) {
                    // The field's edge at x: straight along the middle, rounding off at the ends.
                    const cornerInset = x < cornerRadius ? cornerRadius - x : x > width - cornerRadius ? x - (width - cornerRadius) : 0;
                    const cornerDrop = cornerRadius - Math.sqrt(Math.max(0, cornerRadius ** 2 - cornerInset ** 2));
                    fieldTop = cornerDrop;
                    fieldBottom = height - cornerDrop;
                }
                // Past the ends, closing in the further out.
                const distancePastEnd = x < 0 ? -x : x > width ? x - width : 0;
                const closing = Math.exp(-distancePastEnd / 10);
                topEdgePoints.push([CANVAS_PADDING_PX + x, CANVAS_PADDING_PX + fieldTop - reachAt(x, true) * closing]);
                bottomEdgePoints.push([CANVAS_PADDING_PX + x, CANVAS_PADDING_PX + fieldBottom + reachAt(x, false) * closing]);
            }
            edgeContext.beginPath();
            topEdgePoints.forEach(([x, y], index) => (index ? edgeContext.lineTo(x, y) : edgeContext.moveTo(x, y)));
            [...bottomEdgePoints].reverse().forEach(([x, y]) => edgeContext.lineTo(x, y));
            edgeContext.closePath();
            edgeContext.fill();
            // Fading out from the edge: keep the glow only where a soft shadow of the field reaches.
            maskContext.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
            maskContext.clearRect(0, 0, canvasWidth, canvasHeight);
            maskContext.shadowColor = "black";
            maskContext.shadowBlur = ((REST_REACH_PX + LOUD_REACH_PX * loudness + BURST_REACH_PX * burst) * 1.5 + 10) * pixelRatio;
            maskContext.fillStyle = "black";
            maskContext.beginPath();
            maskContext.roundRect(CANVAS_PADDING_PX, CANVAS_PADDING_PX, width, height, cornerRadius);
            maskContext.fill();
            edgeContext.setTransform(1, 0, 0, 1, 0, 0);
            edgeContext.globalCompositeOperation = "destination-in";
            edgeContext.drawImage(maskCanvas, 0, 0);
            edgeContext.globalCompositeOperation = "source-over";
            // The wide light: the same glow, blurred wider by CSS.
            haloContext.setTransform(1, 0, 0, 1, 0, 0);
            haloContext.clearRect(0, 0, haloCanvas.width, haloCanvas.height);
            haloContext.drawImage(edgeCanvas, 0, 0);
            if (!prefersReducedMotion)
                animationFrameId = requestAnimationFrame(drawFrame);
        };
        const resizeObserver = new ResizeObserver(() => {
            resizeCanvases();
            if (prefersReducedMotion)
                drawFrame(0);
        });
        resizeObserver.observe(box);
        resizeCanvases();
        animationFrameId = requestAnimationFrame(drawFrame);
        return () => {
            cancelAnimationFrame(animationFrameId);
            resizeObserver.disconnect();
            field?.removeEventListener("focusin", startBurst);
        };
    }, []);
    return (<div ref={boxRef} className={styles.glow} aria-hidden data-testid={testId}>
      <canvas ref={haloCanvasRef} className={styles.halo}/>
      <canvas ref={edgeCanvasRef} className={styles.edge}/>
    </div>);
};
/**
 * The chat input's glow during a voice chat; renders nothing while no voice chat is connecting or live. Rendered as
 * the input's underlay: its box covers the input's whole box, under the field, and the field's own surface covers the
 * canvases' middle, so only what is past its edge shows. The field needs a positioned parent with its own stacking
 * context.
 */
export const AgentVoiceInputGlow = (props) => {
    const { agentSpaceId, testId = "agent-voice-input-glow" } = props;
    const isCallActiveHere = useIsAgentVoiceCallActive(agentSpaceId);
    return isCallActiveHere ? <AgentVoiceInputGlowCanvases testId={testId}/> : null;
};
