/**
 * VoiceInput - Web Speech API wrapper for speech recognition
 *
 * Provides voice-to-text input using the browser's Speech Recognition API.
 * Supports continuous recognition, interim results, and multiple languages.
 *
 * @module pwa/voice-input
 */

/**
 * @typedef {Object} VoiceOptions
 * @property {boolean} [continuous=false] - Continue listening after recognition
 * @property {boolean} [interimResults=true] - Return interim results
 * @property {string} [lang='en-US'] - Language code
 * @property {number} [maxAlternatives=1] - Number of alternative results
 */

/**
 * @typedef {Object} VoiceCallbacks
 * @property {Function} [onResult] - Called with final transcript
 * @property {Function} [onInterim] - Called with interim transcript
 * @property {Function} [onEnd] - Called when recognition ends
 * @property {Function} [onError] - Called on error
 * @property {Function} [onStart] - Called when recognition starts
 */

/**
 * VoiceInput - Manages browser speech recognition functionality
 *
 * @class
 */
export class VoiceInput {
    /**
     * @param {VoiceOptions} options - Configuration options
     */
    constructor(options = {}) {
        // Check browser support
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition

        if (!SpeechRecognition) {
            this.supported = false
            console.warn('VoiceInput: Speech Recognition API not supported in this browser')
            return
        }

        this.supported = true
        this.recognition = new SpeechRecognition()

        // Configure recognition
        this.recognition.continuous = options.continuous || false
        this.recognition.interimResults = options.interimResults !== false // default true
        this.recognition.lang = options.lang || 'en-US'
        this.recognition.maxAlternatives = options.maxAlternatives || 1

        this.isListening = false
        this.transcript = ''
    }

    /**
     * Start voice recognition
     *
     * @param {VoiceCallbacks} callbacks - Event callbacks
     * @returns {void}
     */
    start(callbacks = {}) {
        if (!this.supported) {
            const error = new Error('Speech Recognition not supported')
            console.warn('VoiceInput: Cannot start - not supported')
            callbacks.onError && callbacks.onError(error)
            return
        }

        if (this.isListening) {
            console.warn('VoiceInput: Recognition already active')
            return
        }

        const { onResult, onEnd, onError, onStart, onInterim } = callbacks

        // Handle results
        this.recognition.onresult = (event) => {
            let interimTranscript = ''
            let finalTranscript = ''

            for (let i = event.resultIndex; i < event.results.length; i++) {
                const transcript = event.results[i][0].transcript

                if (event.results[i].isFinal) {
                    finalTranscript += transcript + ' '
                } else {
                    interimTranscript += transcript
                }
            }

            // Call appropriate callbacks
            if (finalTranscript) {
                this.transcript = finalTranscript.trim()
                onResult && onResult(this.transcript, true)
            }

            if (interimTranscript) {
                onInterim && onInterim(interimTranscript, false)
            }
        }

        // Handle end
        this.recognition.onend = () => {
            this.isListening = false
            onEnd && onEnd(this.transcript)
        }

        // Handle errors
        this.recognition.onerror = (event) => {
            this.isListening = false

            let errorMessage = 'Recognition error'
            switch (event.error) {
                case 'no-speech':
                    errorMessage = 'No speech detected'
                    break
                case 'audio-capture':
                    errorMessage = 'No microphone available'
                    break
                case 'not-allowed':
                    errorMessage = 'Microphone permission denied'
                    break
                case 'network':
                    errorMessage = 'Network error'
                    break
                default:
                    errorMessage = `Recognition error: ${event.error}`
            }

            console.error('VoiceInput: Recognition error', {
                error: event.error,
                message: errorMessage
            })
            onError && onError(new Error(errorMessage), event.error)
        }

        // Handle start
        this.recognition.onstart = () => {
            this.isListening = true
            this.transcript = ''
            onStart && onStart()
        }

        try {
            this.recognition.start()
        } catch (error) {
            this.isListening = false
            console.error('VoiceInput: Failed to start recognition', {
                error: error.message
            })
            onError && onError(error)
        }
    }

    /**
     * Stop voice recognition
     *
     * @returns {void}
     */
    stop() {
        if (this.isListening && this.recognition) {
            this.recognition.stop()
            this.isListening = false
        }
    }

    /**
     * Abort voice recognition
     *
     * @returns {void}
     */
    abort() {
        if (this.recognition) {
            this.recognition.abort()
            this.isListening = false
        }
    }

    /**
     * Change recognition language
     *
     * @param {string} lang - Language code (e.g., 'en-US', 'es-ES')
     * @returns {void}
     */
    setLanguage(lang) {
        if (this.recognition) {
            this.recognition.lang = lang
        }
    }

    /**
     * Get current transcript
     *
     * @returns {string} The current transcript
     */
    getTranscript() {
        return this.transcript
    }

    /**
     * Check if currently listening
     *
     * @returns {boolean} True if recognition is active
     */
    isActive() {
        return this.isListening
    }

    /**
     * Check if browser supports voice recognition
     *
     * @returns {boolean} True if Speech Recognition API is supported
     * @static
     */
    static isSupported() {
        return !!(window.SpeechRecognition || window.webkitSpeechRecognition)
    }
}

export default VoiceInput
