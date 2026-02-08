/**
 * TextToSpeech - Web Speech API wrapper for text-to-speech functionality
 *
 * Provides a simple interface to the browser's Speech Synthesis API with
 * configurable voice options, rate, pitch, and volume controls. Automatically
 * cleans text for optimal speech output.
 *
 * @module pwa/text-to-speech
 */

/**
 * @typedef {Object} TTSOptions
 * @property {string} [lang='en-US'] - Language code
 * @property {number} [rate=1.0] - Speech rate (0.1 to 10)
 * @property {number} [pitch=1.0] - Voice pitch (0 to 2)
 * @property {number} [volume=1.0] - Volume (0 to 1)
 * @property {number} [maxLength=500] - Maximum text length to speak
 */

/**
 * TextToSpeech - Manages browser text-to-speech functionality
 *
 * @class
 */
export class TextToSpeech {
    /**
     * @param {TTSOptions} options - Configuration options
     */
    constructor(options = {}) {
        // Check browser support
        if (!('speechSynthesis' in window)) {
            this.supported = false
            console.warn('TextToSpeech: Speech Synthesis API not supported in this browser')
            return
        }

        this.supported = true
        this.synth = window.speechSynthesis
        this.utterance = null
        this.isSpeaking = false

        // Default options
        this.options = {
            lang: options.lang || 'en-US',
            rate: options.rate || 1.0,
            pitch: options.pitch || 1.0,
            volume: options.volume || 1.0,
            maxLength: options.maxLength || 500 // Only speak short responses
        }
    }

    /**
     * Speak the given text
     *
     * @param {string} text - The text to speak
     * @param {TTSOptions} options - Optional speech parameters
     * @returns {boolean} True if speech started successfully
     */
    speak(text, options = {}) {
        if (!this.supported) {
            console.warn('TextToSpeech: Speech Synthesis not supported')
            return false
        }

        // Cancel any ongoing speech
        this.stop()

        // Don't speak if text is too long
        if (text.length > this.options.maxLength) {
            return false
        }

        // Clean text for better speech
        const cleanText = this.cleanTextForSpeech(text)

        // Create utterance
        this.utterance = new SpeechSynthesisUtterance(cleanText)

        // Apply options
        this.utterance.lang = options.lang || this.options.lang
        this.utterance.rate = options.rate || this.options.rate
        this.utterance.pitch = options.pitch || this.options.pitch
        this.utterance.volume = options.volume || this.options.volume

        // Set up event listeners
        if (options.onStart) {
            this.utterance.onstart = options.onStart
        }

        if (options.onEnd) {
            this.utterance.onend = options.onEnd
        }

        if (options.onError) {
            this.utterance.onerror = options.onError
        }

        // Track speaking state
        this.utterance.onstart = () => {
            this.isSpeaking = true
            options.onStart && options.onStart()
        }

        this.utterance.onend = () => {
            this.isSpeaking = false
            options.onEnd && options.onEnd()
        }

        this.utterance.onerror = (event) => {
            this.isSpeaking = false
            console.error('TextToSpeech: Speech error', {
                error: event.error,
                type: event.type,
                charIndex: event.charIndex,
                textLength: cleanText.length
            })
            options.onError && options.onError(event)
        }

        // Speak
        try {
            this.synth.speak(this.utterance)
            return true
        } catch (error) {
            console.error('TextToSpeech: Failed to start speech', {
                error: error.message,
                textLength: cleanText.length,
                lang: this.utterance.lang
            })
            this.isSpeaking = false
            return false
        }
    }

    /**
     * Stop speaking
     *
     * @returns {void}
     */
    stop() {
        if (this.synth && this.synth.speaking) {
            this.synth.cancel()
            this.isSpeaking = false
        }
    }

    /**
     * Pause speaking
     *
     * @returns {void}
     */
    pause() {
        if (this.synth && this.synth.speaking) {
            this.synth.pause()
        }
    }

    /**
     * Resume speaking
     *
     * @returns {void}
     */
    resume() {
        if (this.synth && this.synth.paused) {
            this.synth.resume()
        }
    }

    /**
     * Set default options
     *
     * @param {TTSOptions} options - New default options
     * @returns {void}
     */
    setOptions(options) {
        this.options = { ...this.options, ...options }
    }

    /**
     * Get available voices
     *
     * @returns {SpeechSynthesisVoice[]} Array of available voices
     */
    getVoices() {
        if (!this.supported) {
            return []
        }
        return this.synth.getVoices()
    }

    /**
     * Set voice by name or language
     *
     * @param {string} voiceNameOrLang - Voice name or language code
     * @returns {boolean} True if voice was found and set
     */
    setVoice(voiceNameOrLang) {
        if (!this.supported) {
            return false
        }

        const voices = this.getVoices()
        const voice = voices.find(v =>
            v.name === voiceNameOrLang || v.lang === voiceNameOrLang
        )

        if (voice && this.utterance) {
            this.utterance.voice = voice
            return true
        }

        return false
    }

    /**
     * Clean text for better speech output
     *
     * @param {string} text - Raw text to clean
     * @returns {string} Cleaned text suitable for speech
     * @private
     */
    cleanTextForSpeech(text) {
        return text
            // Remove markdown syntax
            .replace(/[*_~`#]/g, '')
            // Remove URLs
            .replace(/https?:\/\/[^\s]+/g, '')
            // Remove extra whitespace
            .replace(/\s+/g, ' ')
            .trim()
    }

    /**
     * Check if currently speaking
     *
     * @returns {boolean} True if speech is active
     */
    isActive() {
        return this.isSpeaking && this.synth?.speaking
    }

    /**
     * Check if browser supports TTS
     *
     * @returns {boolean} True if Speech Synthesis API is supported
     * @static
     */
    static isSupported() {
        return 'speechSynthesis' in window
    }
}

export default TextToSpeech
